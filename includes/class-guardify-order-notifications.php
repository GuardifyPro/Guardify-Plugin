<?php
defined('ABSPATH') || exit;

/**
 * Guardify Order Notifications — Sends SMS on WC order status changes
 * via the Guardify Engine SMS endpoint.
 *
 * Uses template placeholders like {customer_name}, {order_total}, etc.
 * Admin configures which statuses trigger SMS in plugin settings.
 */
class Guardify_Order_Notifications {

    private static $instance = null;
    private $enabled_statuses;
    private $templates;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_sms_notifications_enabled', 'no') !== 'yes') {
            return;
        }

        $this->load_settings();

        // Hook into order status changes
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 3);
    }

    /**
     * Load notification settings from options.
     */
    private function load_settings() {
        $this->enabled_statuses = get_option('guardify_notification_statuses', []);
        if (!is_array($this->enabled_statuses)) {
            $this->enabled_statuses = [];
        }

        $saved = get_option('guardify_notification_templates', []);
        $this->templates = array_merge(self::default_templates(), is_array($saved) ? $saved : []);
    }

    /**
     * Handle WC order status change — send SMS if status is enabled.
     */
    public function handle_status_change($order_id, $old_status, $new_status) {
        $wc_status = 'wc-' . $new_status;

        if (!in_array($wc_status, $this->enabled_statuses, true)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return;
        }

        $message = $this->build_message($wc_status, $order);
        if (empty($message)) {
            return;
        }

        // Send via Engine
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        $api->post('/api/v1/sms/send', [
            'phone'   => $phone,
            'message' => $message,
            'purpose' => 'order_status',
        ]);
    }

    /**
     * Build SMS message from template with placeholder replacement.
     */
    private function build_message($status, $order) {
        if (!isset($this->templates[$status])) {
            return '';
        }

        $template = $this->templates[$status];

        // Gather product names
        $products = [];
        foreach ($order->get_items() as $item) {
            $products[] = $item->get_name();
        }

        $replacements = [
            '{customer_name}' => $order->get_billing_first_name(),
            '{product_name}'  => implode(', ', $products),
            '{order_total}'   => $order->get_total(),
            '{order_number}'  => $order->get_order_number(),
            '{order_date}'    => $order->get_date_created() ? $order->get_date_created()->format('d/m/Y') : '',
            '{siteurl}'       => wp_parse_url(get_site_url(), PHP_URL_HOST),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    /**
     * Default SMS templates per status (Bengali).
     */
    public static function default_templates() {
        return [
            'wc-processing' => "আসসালামু আলাইকুম {customer_name},\nআপনার অর্ডার #{order_number} কনফার্ম হয়েছে ✅\n📦 {product_name}\n💰 {order_total} টাকা\nধন্যবাদ, {siteurl}",
            'wc-on-hold'    => "আসসালামু আলাইকুম {customer_name},\nআপনার অর্ডার #{order_number} হোল্ডে আছে ⏳\nআমরা শীঘ্রই প্রক্রিয়া করব।\nধন্যবাদ, {siteurl}",
            'wc-completed'  => "আসসালামু আলাইকুম {customer_name},\nআপনার অর্ডার #{order_number} সফলভাবে সম্পন্ন হয়েছে ✅\nধন্যবাদ, {siteurl}",
            'wc-cancelled'  => "আসসালামু আলাইকুম {customer_name},\nআপনার অর্ডার #{order_number} বাতিল হয়েছে ❌\nপ্রশ্ন থাকলে যোগাযোগ করুন।\nধন্যবাদ, {siteurl}",
            'wc-refunded'   => "আসসালামু আলাইকুম {customer_name},\nআপনার অর্ডার #{order_number} এর টাকা ফেরত দেওয়া হয়েছে 💰\n{order_total} টাকা\nধন্যবাদ, {siteurl}",
        ];
    }

    /**
     * Get available WC statuses for settings page.
     */
    public static function get_available_statuses() {
        return wc_get_order_statuses();
    }
}
