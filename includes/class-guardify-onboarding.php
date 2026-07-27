<?php
defined('ABSPATH') || exit;

/**
 * Guardify Onboarding — first-run setup.
 *
 * The settings screen has around a dozen toggles, several of which can refuse orders. That
 * is the right amount of control for a merchant who already knows what Guardify does and
 * the wrong amount for one who installed it ten minutes ago: faced with switches labelled
 * "block" they either turn everything on and start losing sales, or turn nothing on and
 * conclude the plugin does nothing. Both end in an uninstall, and the second is the more
 * common.
 *
 * So the wizard asks three questions and makes the rest of the decisions:
 *
 *   1. Connect the site.
 *   2. How careful do you want to be? — one choice, mapped to every toggle behind it.
 *   3. Should we keep backups?
 *
 * Then it scans orders the shop already has and reports what it finds. That last step is
 * the point of the whole flow. A merchant who is told "of your last 200 orders, 7 came
 * from numbers with a poor delivery record, worth ৳14,300" has seen the product work on
 * their own data before it has touched a single live order — which is a far better reason
 * to trust it than any description.
 *
 * Nothing here refuses an order. Every preset starts in a mode that observes and reports;
 * turning on enforcement is a deliberate second act, taken once the merchant has seen the
 * verdicts and agrees with them.
 */
class Guardify_Onboarding {

    private static $instance = null;

    /** Set once setup has been completed or dismissed. */
    const OPT_DONE = 'guardify_onboarding_done';

    /** Transient set at activation, consumed by the redirect. */
    const TRANSIENT_REDIRECT = 'guardify_onboarding_redirect';

    /** Orders examined by the retroactive scan. */
    const SCAN_LIMIT = 200;

    /** Orders per scan request, so one batch never outlives an admin-ajax timeout. */
    const SCAN_CHUNK = 40;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_page'], 5);
        add_action('admin_init', [$this, 'maybe_redirect']);
        add_action('admin_notices', [$this, 'render_notice']);

        add_action('wp_ajax_guardify_onboarding_connect', [$this, 'ajax_connect']);
        add_action('wp_ajax_guardify_onboarding_preset', [$this, 'ajax_apply_preset']);
        add_action('wp_ajax_guardify_onboarding_backup', [$this, 'ajax_backup']);
        add_action('wp_ajax_guardify_onboarding_scan', [$this, 'ajax_scan']);
        add_action('wp_ajax_guardify_onboarding_finish', [$this, 'ajax_finish']);
    }

    /**
     * Flag the wizard to open. Called from the activation hook.
     */
    public static function schedule_redirect() {
        if (!get_option(self::OPT_DONE, false)) {
            set_transient(self::TRANSIENT_REDIRECT, 1, 60);
        }
    }

    public static function is_done() {
        return (bool) get_option(self::OPT_DONE, false);
    }

    /**
     * Register the wizard as a hidden page.
     *
     * Hidden because it is a one-time flow. A permanent "Setup" item in the menu invites
     * merchants to re-run it months later and silently overwrite settings they have since
     * tuned by hand.
     */
    public function register_page() {
        add_submenu_page(
            null,
            __('Guardify সেটআপ', 'guardify-pro'),
            __('সেটআপ', 'guardify-pro'),
            'manage_woocommerce',
            'guardify-setup',
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }
        include GUARDIFY_PATH . 'templates/onboarding-page.php';
    }

    /**
     * Send a freshly activated site to the wizard, once.
     */
    public function maybe_redirect() {
        if (!get_transient(self::TRANSIENT_REDIRECT)) {
            return;
        }
        delete_transient(self::TRANSIENT_REDIRECT);

        // Never hijack a bulk activation — the admin is mid-task on a list of plugins and
        // being thrown into a wizard loses their place.
        if (isset($_GET['activate-multi']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        if (wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        wp_safe_redirect(admin_url('admin.php?page=guardify-setup'));
        exit;
    }

    /**
     * A dismissible prompt for sites that skipped the redirect — updated from an older
     * version, activated in bulk, or activated by someone without the capability.
     */
    public function render_notice() {
        if (self::is_done() || !current_user_can('manage_woocommerce')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, 'guardify') !== false) {
            return; // already inside Guardify; the pages say this themselves
        }

        printf(
            '<div class="notice notice-info guardify-notice"><p><strong>%s</strong> %s <a href="%s" class="button button-primary" style="margin-left:8px">%s</a></p></div>',
            esc_html__('Guardify চালু হয়েছে।', 'guardify-pro'),
            esc_html__('৩ মিনিটের সেটআপ শেষ করলে আপনার দোকান সুরক্ষিত হবে।', 'guardify-pro'),
            esc_url(admin_url('admin.php?page=guardify-setup')),
            esc_html__('সেটআপ শুরু করুন', 'guardify-pro')
        );
    }

    // ─── Presets ─────────────────────────────────────────────────────────

    /**
     * The three protection levels, and every option each one sets.
     *
     * Two rules hold across all of them, and they are the reason a merchant can pick any
     * one without reading the details:
     *
     *   - `smart_filter_dry_run` is 'yes' everywhere. Guardify scores and records but never
     *     refuses an order during setup. A plugin that blocks a real customer on its first
     *     day is uninstalled that day, and the merchant tells other merchants.
     *   - `smart_filter_skip_new` is 'yes' everywhere. Most customers are new to any given
     *     shop, and a first-time buyer is not a risk signal.
     *
     * What varies is what gets watched and how loudly.
     */
    public static function presets() {
        return [
            'observe' => [
                'label'       => __('শুধু পর্যবেক্ষণ', 'guardify-pro'),
                'summary'     => __('কোনো অর্ডারে হাত দেওয়া হবে না। শুধু ঝুঁকির স্কোর ও রিপোর্ট দেখতে পাবেন।', 'guardify-pro'),
                'best_for'    => __('নতুন দোকান, অথবা আগে দেখে নিতে চাইলে', 'guardify-pro'),
                'options'     => [
                    'guardify_smart_filter_enabled'  => 'yes',
                    'guardify_smart_filter_dry_run'  => 'yes',
                    'guardify_smart_filter_action'   => 'flag',
                    'guardify_smart_filter_skip_new' => 'yes',
                    'guardify_report_column_enabled' => 'yes',
                    'guardify_phone_history_enabled' => 'yes',
                    'guardify_otp_enabled'           => 'no',
                    'guardify_vpn_block_enabled'     => 'no',
                    'guardify_repeat_blocker_enabled' => 'no',
                    'guardify_fraud_detection_enabled' => 'no',
                ],
            ],
            'balanced' => [
                'label'    => __('সুপারিশকৃত', 'guardify-pro'),
                'summary'  => __('ঝুঁকিপূর্ণ অর্ডার চিহ্নিত হবে ও ফ্রড রেকর্ড রাখা হবে। অর্ডার বাতিল হবে না।', 'guardify-pro'),
                'best_for' => __('বেশিরভাগ দোকানের জন্য', 'guardify-pro'),
                'options'  => [
                    'guardify_smart_filter_enabled'  => 'yes',
                    'guardify_smart_filter_dry_run'  => 'yes',
                    'guardify_smart_filter_action'   => 'flag',
                    'guardify_smart_filter_skip_new' => 'yes',
                    'guardify_report_column_enabled' => 'yes',
                    'guardify_phone_history_enabled' => 'yes',
                    'guardify_fraud_detection_enabled' => 'yes',
                    // Repeat blocking catches the same number ordering four times in an
                    // hour. That is a bot or a mistake, not a customer, and it is the one
                    // rule with essentially no false positives.
                    'guardify_repeat_blocker_enabled' => 'yes',
                    'guardify_otp_enabled'            => 'no',
                    'guardify_vpn_block_enabled'      => 'no',
                ],
            ],
            'strict' => [
                'label'    => __('কড়া', 'guardify-pro'),
                'summary'  => __('ঝুঁকিপূর্ণ অর্ডারে OTP যাচাই চাওয়া হবে। এখনো কোনো অর্ডার বাতিল হবে না।', 'guardify-pro'),
                'best_for' => __('বেশি COD ফেরত আসে এমন দোকান', 'guardify-pro'),
                'options'  => [
                    'guardify_smart_filter_enabled'  => 'yes',
                    'guardify_smart_filter_dry_run'  => 'yes',
                    'guardify_smart_filter_action'   => 'otp',
                    'guardify_smart_filter_skip_new' => 'yes',
                    'guardify_report_column_enabled' => 'yes',
                    'guardify_phone_history_enabled' => 'yes',
                    'guardify_fraud_detection_enabled' => 'yes',
                    'guardify_repeat_blocker_enabled'  => 'yes',
                    'guardify_otp_enabled'             => 'yes',
                    // Only risky orders, never all of them. OTP on every checkout is a
                    // conversion tax paid by the 95% of customers who are fine.
                    'guardify_otp_scope'               => 'risky',
                    // VPN detection stays off even here. Bangladeshi mobile networks put
                    // large numbers of ordinary customers behind carrier-grade NAT that
                    // reads as a VPN, so enforcing it refuses real buyers.
                    'guardify_vpn_block_enabled'       => 'no',
                ],
            ],
        ];
    }

    // ─── AJAX ────────────────────────────────────────────────────────────

    private function guard() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
    }

    /**
     * Step 1: save the API key and confirm the engine accepts it.
     */
    public function ajax_connect() {
        $this->guard();

        $key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if ($key === '') {
            wp_send_json_error(__('API কী দিন।', 'guardify-pro'));
        }

        $api = new Guardify_API();
        $api->save_credentials($key);

        // Legacy keys collect their signing secret on first use. Doing it here rather than
        // lazily means a bad key fails inside the wizard, where the merchant is looking at
        // it, instead of silently at the first checkout.
        $api = new Guardify_API();
        $api->ensure_signed();

        $status = $api->get('/api/v1/auth/status');

        if (!is_array($status) || (isset($status['success']) && $status['success'] === false)) {
            $api->clear_credentials();
            $message = isset($status['error']) ? $status['error'] : __('কী যাচাই করা যায়নি। কী-টি আবার দেখুন।', 'guardify-pro');
            wp_send_json_error($message);
        }

        wp_send_json_success([
            'plan'    => isset($status['plan']) ? sanitize_text_field($status['plan']) : '',
            'state'   => isset($status['status']) ? sanitize_text_field($status['status']) : '',
            'signed'  => $api->is_signed(),
            'message' => __('সংযোগ সফল হয়েছে।', 'guardify-pro'),
        ]);
    }

    /**
     * Step 2: apply a protection preset.
     */
    public function ajax_apply_preset() {
        $this->guard();

        $name    = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
        $presets = self::presets();

        if (!isset($presets[$name])) {
            wp_send_json_error(__('অজানা সুরক্ষা লেভেল।', 'guardify-pro'));
        }

        foreach ($presets[$name]['options'] as $option => $value) {
            update_option($option, $value);
        }
        update_option('guardify_onboarding_preset', $name);

        wp_send_json_success(['message' => $presets[$name]['label'] . __(' চালু হয়েছে।', 'guardify-pro')]);
    }

    /**
     * Step 3: turn on managed backups.
     */
    public function ajax_backup() {
        $this->guard();

        $enabled = isset($_POST['enabled']) && $_POST['enabled'] === 'yes';

        update_option('guardify_backup_enabled', $enabled ? 'yes' : 'no');
        if ($enabled) {
            update_option('guardify_backup_frequency', 'daily');
            // Pre-dawn Dhaka time. Shops are idle, and the dump runs in slices anyway, so
            // the worst case lands where it costs the fewest customers.
            update_option('guardify_backup_time', '04:00');
            update_option('guardify_backup_timezone', 'Asia/Dhaka');
        }

        Guardify_Backup::get_instance()->schedule_backup();

        wp_send_json_success([
            'message' => $enabled
                ? __('প্রতিদিন রাত ৪টায় স্বয়ংক্রিয় ব্যাকআপ চালু হয়েছে।', 'guardify-pro')
                : __('ব্যাকআপ বন্ধ রাখা হয়েছে।', 'guardify-pro'),
        ]);
    }

    /**
     * Step 4: score orders the shop already has.
     *
     * Runs in chunks, driven by the browser, so a shop with two hundred orders never holds
     * one admin-ajax request open long enough to hit a timeout — the same reason the backup
     * dump takes slices.
     */
    public function ajax_scan() {
        $this->guard();

        $offset = isset($_POST['offset']) ? absint($_POST['offset']) : 0;
        if ($offset >= self::SCAN_LIMIT) {
            wp_send_json_success(['done' => true, 'scanned' => 0, 'risky' => [], 'value' => 0]);
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error(__('সংযুক্ত নয়।', 'guardify-pro'));
        }

        $orders = wc_get_orders([
            'limit'   => self::SCAN_CHUNK,
            'offset'  => $offset,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => ['processing', 'on-hold', 'pending'],
            'return'  => 'objects',
        ]);

        if (empty($orders)) {
            wp_send_json_success(['done' => true, 'scanned' => 0, 'risky' => [], 'value' => 0]);
        }

        $by_phone = [];
        foreach ($orders as $order) {
            $phone = Guardify_Phone_Util::clean($order->get_billing_phone());
            if ($phone === null) {
                continue;
            }
            $by_phone[$phone][] = $order;
        }

        $risky = [];
        $value = 0.0;

        if (!empty($by_phone)) {
            $result = $api->post('/api/v1/courier/summary/batch', ['phones' => array_keys($by_phone)]);

            if (is_array($result) && !empty($result['results'])) {
                foreach ($by_phone as $phone => $phone_orders) {
                    $summary = isset($result['results'][$phone]) ? $result['results'][$phone] : null;
                    if (!is_array($summary) || empty($summary['risk'])) {
                        continue;
                    }

                    $risk = $summary['risk'];
                    $action = isset($risk['action']) ? $risk['action'] : 'allow';

                    // Only orders the engine would actually have acted on. Listing
                    // everything with a middling score would pad the number and make the
                    // finding meaningless — the merchant needs to recognise these as
                    // orders they would want a second look at.
                    if ($action === 'allow' || $action === '') {
                        continue;
                    }

                    foreach ($phone_orders as $order) {
                        $total   = (float) $order->get_total();
                        $value  += $total;
                        $risky[] = [
                            'id'     => $order->get_id(),
                            'number' => $order->get_order_number(),
                            'name'   => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                            'phone'  => $phone,
                            'total'  => $total,
                            'score'  => isset($risk['score']) ? (int) $risk['score'] : null,
                            'band'   => isset($risk['band']) ? (string) $risk['band'] : 'unknown',
                            'action' => $action,
                            'url'    => $order->get_edit_order_url(),
                        ];
                    }
                }
            }
        }

        wp_send_json_success([
            'done'    => count($orders) < self::SCAN_CHUNK || ($offset + self::SCAN_CHUNK) >= self::SCAN_LIMIT,
            'scanned' => count($orders),
            'risky'   => $risky,
            'value'   => $value,
        ]);
    }

    /**
     * Mark setup complete.
     */
    public function ajax_finish() {
        $this->guard();

        update_option(self::OPT_DONE, time());
        delete_transient(self::TRANSIENT_REDIRECT);

        wp_send_json_success(['redirect' => admin_url('admin.php?page=guardify-pro')]);
    }
}
