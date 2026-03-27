<?php
defined('ABSPATH') || exit;

/**
 * Guardify Repeat Order Blocker — Prevents duplicate orders from the same phone
 * within a configurable time window.
 *
 * This works entirely locally via WooCommerce order queries — no Engine API call needed.
 */
class Guardify_Repeat_Blocker {

    private static $instance = null;
    private $enabled;
    private $time_limit;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->enabled    = get_option('guardify_repeat_blocker_enabled', 'no') === 'yes';
        $this->time_limit = absint(get_option('guardify_repeat_blocker_hours', 24));

        if (!$this->enabled) {
            return;
        }

        add_action('woocommerce_checkout_process', [$this, 'check_recent_orders'], 10);
        add_action('wp_ajax_guardify_check_repeat', [$this, 'ajax_check_repeat']);
        add_action('wp_ajax_nopriv_guardify_check_repeat', [$this, 'ajax_check_repeat']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Server-side: Block checkout if a recent order exists for this phone.
     */
    public function check_recent_orders() {
        if (current_user_can('manage_woocommerce')) {
            return; // Skip for admins
        }

        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            return;
        }

        if ($this->has_recent_order($phone)) {
            wc_add_notice(
                sprintf(
                    'এই ফোন নম্বর থেকে ইতিমধ্যে একটি অর্ডার করা হয়েছে। অনুগ্রহ করে %d ঘণ্টা পর আবার চেষ্টা করুন।',
                    $this->time_limit
                ),
                'error'
            );
        }
    }

    /**
     * AJAX: Real-time phone check for frontend.
     */
    public function ajax_check_repeat() {
        check_ajax_referer('guardify_repeat_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error(['blocked' => false, 'message' => 'Invalid phone']);
        }

        $blocked = $this->has_recent_order($phone);

        if ($blocked) {
            wp_send_json_error([
                'blocked' => true,
                'message' => sprintf(
                    'এই নম্বর থেকে সম্প্রতি অর্ডার করা হয়েছে। %d ঘণ্টা পর আবার চেষ্টা করুন।',
                    $this->time_limit
                ),
            ]);
        }

        wp_send_json_success(['blocked' => false]);
    }

    /**
     * Check if there's a recent WC order with this phone number.
     */
    private function has_recent_order($phone) {
        // Also check with 88 prefix variant
        $phone_variants = [$phone];
        if (strpos($phone, '88') !== 0) {
            $phone_variants[] = '88' . $phone;
        }

        foreach ($phone_variants as $p) {
            $orders = wc_get_orders([
                'billing_phone' => $p,
                'limit'         => 1,
                'orderby'       => 'date',
                'order'         => 'DESC',
                'status'        => ['pending', 'processing', 'on-hold', 'completed', 'otp-pending'],
            ]);

            if (!empty($orders)) {
                $order = reset($orders);
                $order_time = $order->get_date_created();
                if ($order_time) {
                    $diff_hours = (time() - $order_time->getTimestamp()) / 3600;
                    if ($diff_hours < $this->time_limit) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Enqueue frontend scripts for live phone check.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'guardify-repeat-blocker',
            GUARDIFY_URL . 'assets/js/repeat-blocker.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-repeat-blocker', 'guardifyRepeat', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_repeat_nonce'),
        ]);
    }
}
