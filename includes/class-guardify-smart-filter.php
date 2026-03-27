<?php
defined('ABSPATH') || exit;

/**
 * Guardify Smart Order Filter — Blocks or flags orders based on DP ratio.
 *
 * Checks the customer's delivery performance (DP) via the Guardify Engine
 * during checkout. If DP is below threshold, the order can be blocked or
 * flagged for OTP verification (Phase 4).
 */
class Guardify_Smart_Filter {

    private static $instance = null;
    private $enabled;
    private $threshold;
    private $action;
    private $skip_new;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->enabled   = get_option('guardify_smart_filter_enabled', 'yes') === 'yes';
        $this->threshold = (float) get_option('guardify_smart_filter_threshold', 70);
        $this->action    = get_option('guardify_smart_filter_action', 'block'); // block | otp | flag
        $this->skip_new  = get_option('guardify_smart_filter_skip_new', 'yes') === 'yes';

        if ($this->enabled) {
            add_action('woocommerce_checkout_process', [$this, 'check_dp_at_checkout'], 20);
            add_action('wp_ajax_guardify_check_dp', [$this, 'ajax_check_dp']);
            add_action('wp_ajax_nopriv_guardify_check_dp', [$this, 'ajax_check_dp']);

            // Provide checkout nonce for frontend AJAX (used by smart filter + fraud detection)
            add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_data']);
        }
    }

    /**
     * Check delivery performance during checkout.
     */
    public function check_dp_at_checkout() {
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return; // Fail open if not connected
        }

        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            return; // Phone validation handles invalid numbers
        }

        $result = $api->get('/api/v1/courier/dp-ratio', ['phone' => $phone]);

        if (!isset($result['dp_ratio']) && !(isset($result['data']['dp_ratio']))) {
            return; // Fail open on API error
        }

        $dp_ratio = isset($result['dp_ratio']) ? (float) $result['dp_ratio'] : (float) $result['data']['dp_ratio'];
        $total    = isset($result['total']) ? (int) $result['total'] : (isset($result['data']['total']) ? (int) $result['data']['total'] : 0);

        // Skip new customers (no parcel history)
        if ($this->skip_new && $total === 0) {
            return;
        }

        // Check threshold
        if ($dp_ratio >= $this->threshold) {
            return; // DP is good
        }

        // Below threshold — take action
        switch ($this->action) {
            case 'block':
                wc_add_notice(
                    sprintf(
                        'এই ফোন নম্বরের ডেলিভারি পারফরম্যান্স অপর্যাপ্ত (%.1f%%)। অর্ডার প্লেস করা যাচ্ছে না।',
                        $dp_ratio
                    ),
                    'error'
                );
                break;

            case 'flag':
                // Store in order meta for admin review (order still goes through)
                if (WC()->session) {
                    WC()->session->set('guardify_dp_flagged', [
                        'phone'    => $phone,
                        'dp_ratio' => $dp_ratio,
                        'total'    => $total,
                    ]);
                }
                add_action('woocommerce_checkout_order_processed', [$this, 'save_flag_to_order'], 10, 1);
                break;

            case 'otp':
                // Require OTP verification for low-DP customers
                $otp_enabled = get_option('guardify_otp_enabled', 'no') === 'yes';
                if ($otp_enabled && WC()->session) {
                    $verified = WC()->session->get('guardify_otp_verified', false);
                    if (!$verified) {
                        wc_add_notice(
                            sprintf(
                                'এই ফোন নম্বরের DP রেশিও কম (%.1f%%)। অর্ডার দিতে ফোন ভেরিফিকেশন প্রয়োজন।',
                                $dp_ratio
                            ),
                            'error'
                        );
                    }
                } elseif (!$otp_enabled) {
                    // OTP disabled — fall back to block
                    wc_add_notice(
                        sprintf(
                            'এই ফোন নম্বরের ডেলিভারি পারফর্ম্যান্স অপর্যাপ্ত (%.1f%%)। অর্ডার প্লেস করা যাচ্ছে না।',
                            $dp_ratio
                        ),
                        'error'
                    );
                }
                if (WC()->session) {
                    WC()->session->set('guardify_dp_flagged', [
                        'phone'    => $phone,
                        'dp_ratio' => $dp_ratio,
                        'total'    => $total,
                    ]);
                }
                add_action('woocommerce_checkout_order_processed', [$this, 'save_flag_to_order'], 10, 1);
                break;
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
                        'Guardify: কম DP রেশিও (%.1f%%, মোট %d পার্সেল) — ফ্ল্যাগ করা হয়েছে।',
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
        $result = $api->get('/api/v1/courier/dp-ratio', ['phone' => $phone]);

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
     * Enqueue checkout data (nonce) for frontend AJAX.
     */
    public function enqueue_checkout_data() {
        if (!is_checkout()) {
            return;
        }

        // Register inline script with checkout nonce (used by smart filter + fraud detection AJAX)
        wp_register_script('guardify-checkout', false, [], GUARDIFY_VERSION, true);
        wp_enqueue_script('guardify-checkout');
        wp_localize_script('guardify-checkout', 'guardifyCheckout', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_checkout_nonce'),
        ]);
    }
}
