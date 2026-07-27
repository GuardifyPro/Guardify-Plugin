<?php
defined('ABSPATH') || exit;

/**
 * Guardify Smart Order Filter — acts on the engine's risk verdict at checkout.
 *
 * The verdict is computed by the engine, which weighs the customer's delivery history
 * against how much of it there is. This class decides what to do with that verdict under
 * the merchant's configured ceiling, and enforces the two rules that keep the feature from
 * costing a shop money: it fails open when the engine cannot be reached, and it observes
 * silently until the merchant has seen what it would have done.
 */
class Guardify_Smart_Filter {

    private static $instance = null;
    private $enabled;
    private $threshold;
    private $action;
    private $skip_new;
    private $dry_run;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->enabled   = get_option('guardify_smart_filter_enabled', 'yes') === 'yes';
        $this->threshold = (float) get_option('guardify_smart_filter_threshold', 70);

        // Defaults to flag, not block.
        //
        // Activating a plugin must not start refusing a shop's customers on a threshold the
        // merchant never chose. A merchant who loses good orders uninstalls and tells other
        // merchants; one who is shown what would have been caught upgrades. Blocking is
        // available, but it has to be a decision.
        $this->action   = get_option('guardify_smart_filter_action', 'flag'); // flag | otp | advance_payment | block
        $this->skip_new = get_option('guardify_smart_filter_skip_new', 'yes') === 'yes';

        // Observation mode, on until the merchant turns it off. The onboarding flow shows
        // them the orders it would have acted on before asking them to enable enforcement.
        $this->dry_run = get_option('guardify_smart_filter_dry_run', 'yes') === 'yes';

        if ($this->enabled) {
            add_action('woocommerce_checkout_process', [$this, 'check_dp_at_checkout'], 20);
            add_action('wp_ajax_guardify_check_dp', [$this, 'ajax_check_dp']);
            add_action('wp_ajax_nopriv_guardify_check_dp', [$this, 'ajax_check_dp']);

            // Frontend assets and checkout nonce
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        }
    }

    /**
     * How severe each action is, so a merchant's configured ceiling can be compared
     * against the engine's recommendation.
     */
    const SEVERITY = ['allow' => 0, 'flag' => 1, 'otp' => 2, 'advance_payment' => 3, 'block' => 4];

    /**
     * Assess the customer during checkout and act on the verdict.
     *
     * The decision is made by the engine, not here. A raw delivery ratio compared against a
     * fixed threshold cannot tell one parcel from two hundred: a customer with 2 parcels and
     * 1 return sits at 50% and would be refused on the strength of a single coin flip. The
     * engine's assessment carries a confidence level and will not recommend a block on thin
     * evidence — it asks for partial prepayment instead, which keeps the sale alive.
     *
     * Order value is sent because the same customer is a different decision at ৳400 and at
     * ৳9,000, and the merchant's policy for that lives on the engine.
     */
    public function check_dp_at_checkout() {
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return; // Fail open if not connected
        }

        $phone = Guardify_Phone_Util::clean(
            isset($_POST['billing_phone']) ? wp_unslash($_POST['billing_phone']) : ''
        );
        if ($phone === null) {
            return; // Phone validation handles invalid numbers
        }

        $order_value = (WC()->cart && method_exists(WC()->cart, 'get_total'))
            ? (float) WC()->cart->get_total('edit')
            : 0.0;

        $assessment = $this->assess($api, $phone, $order_value);
        if ($assessment === null) {
            // Fail open. An engine that is slow or unreachable must never cost the merchant
            // a sale — a refused order is a permanent loss, whereas an unscreened one is the
            // risk the merchant was already carrying before installing anything.
            return;
        }

        $recommended = isset($assessment['action']) ? (string) $assessment['action'] : 'allow';
        $reason      = isset($assessment['action_reason']) ? (string) $assessment['action_reason'] : '';
        $band        = isset($assessment['band']) ? (string) $assessment['band'] : 'unknown';
        $score       = isset($assessment['score']) ? (int) $assessment['score'] : 0;

        $effective = self::effective_action($recommended, $this->action);

        // Recorded even when nothing is enforced: a merchant reviewing an order later wants
        // to see that the customer was checked and what the verdict was.
        $this->remember($phone, $score, $band, $assessment, $effective);

        if ($effective === 'allow' || $effective === 'flag') {
            return;
        }

        // Dry run observes without interfering, and is the default for a fresh install.
        // The alternative is a plugin that begins refusing a stranger's customers within
        // minutes of activation, on thresholds that merchant never chose. They review what
        // would have happened, then opt in.
        if ($this->dry_run) {
            return;
        }

        switch ($effective) {
            case 'block':
                wc_add_notice(
                    $reason !== ''
                        ? esc_html($reason)
                        : esc_html__('এই নম্বরের ডেলিভারি রেকর্ড খুব দুর্বল — অর্ডার প্লেস করা যাচ্ছে না।', 'guardify-pro'),
                    'error'
                );
                break;

            case 'advance_payment':
                $pct = isset($assessment['recommended_advance_pct']) ? (int) $assessment['recommended_advance_pct'] : 20;
                wc_add_notice(
                    sprintf(
                        /* translators: %d: advance payment percentage */
                        esc_html__('এই অর্ডারের জন্য %d%% অ্যাডভান্স পেমেন্ট প্রয়োজন। অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন।', 'guardify-pro'),
                        $pct
                    ),
                    'error'
                );
                break;

            case 'otp':
                // Only demand OTP when it can actually be satisfied. With OTP switched off
                // the customer has no way to verify, so refusing here would make the order
                // unplaceable with no path forward. It goes through flagged instead.
                if (get_option('guardify_otp_enabled', 'no') !== 'yes' || !WC()->session) {
                    break;
                }
                if (!WC()->session->get('guardify_otp_verified', false)) {
                    wc_add_notice(
                        esc_html__('অর্ডার সম্পন্ন করতে ফোন নম্বর ভেরিফিকেশন প্রয়োজন।', 'guardify-pro'),
                        'error'
                    );
                }
                break;
        }
    }

    /**
     * Clamp the engine's recommendation to the merchant's configured ceiling.
     *
     * A merchant who selected "flag only" must never have an order blocked, whatever the
     * engine recommends — their setting is a ceiling on severity, not a hint. The reverse
     * does not hold: a merchant who allows blocking still gets a softer action when the
     * engine judges the evidence too thin to justify one.
     *
     * An unrecognised recommendation is treated as allow. A future engine action this
     * plugin version does not know about must not be interpreted as a block.
     *
     * @param string $recommended Action from the engine.
     * @param string $ceiling     Merchant's most severe permitted action.
     * @return string
     */
    public static function effective_action($recommended, $ceiling) {
        // Both inputs are normalised to known actions before comparison, and the *normalised*
        // value is what gets returned. Passing an unrecognised string straight through would
        // leave it to fall silently past every case in the caller's switch — behaviourally
        // harmless today, but it means the function's contract is "sometimes returns an
        // action", which the next caller will get wrong.
        $safe_recommended = isset(self::SEVERITY[$recommended]) ? $recommended : 'allow';
        $safe_ceiling     = isset(self::SEVERITY[$ceiling]) ? $ceiling : 'flag';

        return self::SEVERITY[$safe_recommended] > self::SEVERITY[$safe_ceiling]
            ? $safe_ceiling
            : $safe_recommended;
    }

    /**
     * Fetch an assessment, cached briefly per phone and order-value band.
     *
     * The cache key includes the value band because the recommendation depends on it: a
     * verdict cached for a ৳300 order must not be reused for a ৳9,000 one.
     *
     * @return array|null The assessment, or null when it could not be obtained.
     */
    private function assess($api, $phone, $order_value) {
        $band_key  = (int) floor($order_value / 1000); // ৳1,000 granularity
        $cache_key = 'gf_risk_' . md5($phone . '|' . $band_key);

        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $api->post(
            '/api/v1/risk/assess',
            ['phone' => $phone, 'order_value' => $order_value],
            Guardify_API::checkout_opts()
        );

        $assessment = null;
        if (isset($result['assessment']) && is_array($result['assessment'])) {
            $assessment = $result['assessment'];
        } elseif (isset($result['data']['assessment']) && is_array($result['data']['assessment'])) {
            $assessment = $result['data']['assessment'];
        }

        if ($assessment === null) {
            return null;
        }

        set_transient($cache_key, $assessment, 2 * MINUTE_IN_SECONDS);
        return $assessment;
    }

    /**
     * Stash the verdict for the order-processed hook, so it reaches order meta.
     */
    private function remember($phone, $score, $band, $assessment, $effective) {
        if (!WC()->session) {
            return;
        }

        WC()->session->set('guardify_dp_flagged', [
            'phone'   => $phone,
            'score'   => $score,
            'band'    => $band,
            'action'  => $effective,
            'dry_run' => $this->dry_run,
            'reason'  => isset($assessment['action_reason']) ? (string) $assessment['action_reason'] : '',
            // Kept under their original keys so existing report columns keep working.
            'dp_ratio' => isset($assessment['observed_rate']) ? (float) $assessment['observed_rate'] : 0.0,
            'total'    => isset($assessment['total_parcels']) ? (int) $assessment['total_parcels'] : 0,
        ]);

        if (!has_action('woocommerce_checkout_order_processed', [$this, 'save_flag_to_order'])) {
            add_action('woocommerce_checkout_order_processed', [$this, 'save_flag_to_order'], 10, 1);
        }
    }

    /**
     * Save DP flag data to order meta.
     */
    public function save_flag_to_order($order_id) {
        if (!WC()->session) {
            return;
        }
        $flag = WC()->session->get('guardify_dp_flagged');
        if ($flag) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_guardify_dp_flagged', 'yes');
                $order->update_meta_data('_guardify_dp_ratio', $flag['dp_ratio']);
                $order->update_meta_data('_guardify_dp_total_parcels', $flag['total']);
                $order->add_order_note(
                    sprintf(
                        __('Guardify: কম DP রেশিও (%.1f%%, মোট %d পার্সেল) — ফ্ল্যাগ করা হয়েছে।', 'guardify-pro'),
                        $flag['dp_ratio'],
                        $flag['total']
                    )
                );
                $order->save();
            }
            WC()->session->set('guardify_dp_flagged', null);
        }
    }

    /**
     * AJAX: Quick DP check (for frontend live preview).
     */
    public function ajax_check_dp() {
        check_ajax_referer('guardify_checkout_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error('Invalid phone');
        }

        $api = new Guardify_API();
        // Use transient cache for DP check (2 min TTL)
        $cache_key = 'gf_dp_' . md5($phone);
        $result = get_transient($cache_key);
        if (false === $result) {
            $result = $api->get('/api/v1/courier/dp-ratio', ['phone' => $phone]);
            if (isset($result['dp_ratio']) || (isset($result['data']['dp_ratio']))) {
                set_transient($cache_key, $result, 2 * MINUTE_IN_SECONDS);
            }
        }

        // Normalize wrapped API response
        $d = isset($result['data']) ? $result['data'] : $result;

        if (isset($d['dp_ratio'])) {
            wp_send_json_success([
                'dp_ratio'   => $d['dp_ratio'],
                'risk_level' => $d['risk_level'] ?? 'unknown',
                'total'      => $d['total'] ?? 0,
                'delivered'  => $d['delivered'] ?? 0,
            ]);
        }

        wp_send_json_error('DP check failed');
    }

    /**
     * Enqueue frontend scripts and styles for live DP checking.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'guardify-smart-filter',
            GUARDIFY_URL . 'assets/css/smart-filter.css',
            [],
            GUARDIFY_VERSION
        );

        // JS
        wp_enqueue_script(
            'guardify-smart-filter',
            GUARDIFY_URL . 'assets/js/smart-filter.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-smart-filter', 'guardifySmartFilter', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'nonce'     => wp_create_nonce('guardify_checkout_nonce'),
            'enabled'   => $this->enabled ? 'yes' : 'no',
            'threshold' => $this->threshold,
            'action'    => $this->action,
            'skipNew'   => $this->skip_new ? 'yes' : 'no',
        ]);

        // Also register the checkout nonce globally for other features
        wp_register_script('guardify-checkout', false, [], GUARDIFY_VERSION, true);
        wp_enqueue_script('guardify-checkout');
        wp_localize_script('guardify-checkout', 'guardifyCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_checkout_nonce'),
        ]);
    }
}
