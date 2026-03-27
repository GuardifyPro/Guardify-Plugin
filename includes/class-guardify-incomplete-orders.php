<?php
defined('ABSPATH') || exit;

/**
 * Guardify Incomplete Orders — Captures checkout form data before order placement.
 * Stores locally in a custom DB table, allows admin to view, send SMS reminders,
 * and convert to WC orders.
 */
class Guardify_Incomplete_Orders {

    private static $instance = null;
    private $table_name;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'guardify_incomplete_orders';

        if (get_option('guardify_incomplete_orders_enabled', 'no') !== 'yes') {
            return;
        }

        // Frontend capture
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_guardify_store_incomplete', [$this, 'ajax_store']);
        add_action('wp_ajax_nopriv_guardify_store_incomplete', [$this, 'ajax_store']);

        // Mark completed when order is placed
        add_action('woocommerce_thankyou', [$this, 'mark_recovered']);

        // Admin AJAX
        add_action('wp_ajax_guardify_delete_incomplete', [$this, 'ajax_delete']);
        add_action('wp_ajax_guardify_send_recovery_sms', [$this, 'ajax_send_sms']);
        add_action('wp_ajax_guardify_convert_incomplete', [$this, 'ajax_convert']);

        // Cleanup old records daily
        if (!wp_next_scheduled('guardify_cleanup_incomplete')) {
            wp_schedule_event(time(), 'daily', 'guardify_cleanup_incomplete');
        }
        add_action('guardify_cleanup_incomplete', [$this, 'cleanup']);
    }

    /**
     * Create the DB table on plugin activation.
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            phone VARCHAR(20) NOT NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            cart_data LONGTEXT NULL,
            cart_total DECIMAL(10,2) NULL,
            status ENUM('pending','recovered','expired') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone),
            INDEX idx_status (status)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Frontend: Capture checkout form data.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_script(
            'guardify-incomplete',
            GUARDIFY_URL . 'assets/js/incomplete-orders.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-incomplete', 'guardifyIncomplete', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_incomplete_nonce'),
        ]);
    }

    /**
     * AJAX: Store incomplete order data.
     */
    public function ajax_store() {
        check_ajax_referer('guardify_incomplete_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error('Invalid phone');
        }

        // Rate limit: max 5 captures per phone per 10 minutes
        $throttle_key = 'gf_ic_' . $phone;
        $count = (int) get_transient($throttle_key);
        if ($count >= 5) {
            wp_send_json_error('Too many requests');
        }
        set_transient($throttle_key, $count + 1, 10 * MINUTE_IN_SECONDS);

        global $wpdb;

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $address = isset($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
        $city = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';

        // Get cart data
        $cart_items = [];
        $cart_total = 0;
        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                $product = $item['data'];
                $cart_items[] = [
                    'product_id' => $item['product_id'],
                    'name' => $product->get_name(),
                    'qty'  => $item['quantity'],
                    'price' => $product->get_price(),
                ];
            }
            $cart_total = WC()->cart->get_total('edit');
        }

        // Check for existing pending record with same phone (update instead of insert)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE phone = %s AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
            $phone
        ));

        if ($existing) {
            $wpdb->update($this->table_name, [
                'name'       => $name,
                'address'    => $address,
                'city'       => $city,
                'cart_data'  => wp_json_encode($cart_items),
                'cart_total' => $cart_total,
                'created_at' => current_time('mysql'),
            ], ['id' => $existing], ['%s', '%s', '%s', '%s', '%f', '%s'], ['%d']);
        } else {
            $wpdb->insert($this->table_name, [
                'name'       => $name,
                'phone'      => $phone,
                'address'    => $address,
                'city'       => $city,
                'cart_data'  => wp_json_encode($cart_items),
                'cart_total' => $cart_total,
            ], ['%s', '%s', '%s', '%s', '%s', '%f']);
        }

        wp_send_json_success();
    }

    /**
     * Mark as recovered when order completes.
     */
    public function mark_recovered($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            return;
        }

        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['status' => 'recovered'],
            ['phone' => $phone, 'status' => 'pending'],
            ['%s'],
            ['%s', '%s']
        );
    }

    /**
     * AJAX: Delete incomplete order.
     */
    public function ajax_delete() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id) {
            global $wpdb;
            $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Send recovery SMS to incomplete order customer.
     */
    public function ajax_send_sms() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));

        if (!$row) {
            wp_send_json_error('রেকর্ড পাওয়া যায়নি');
        }

        $site = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $message = sprintf(
            "আসসালামু আলাইকুম %s,\nআপনি %s থেকে অর্ডার সম্পন্ন করেননি। আপনার কার্টে পণ্য অপেক্ষা করছে!\nএখনই অর্ডার করুন: %s\nধন্যবাদ",
            $row->name ?: 'গ্রাহক',
            $site,
            get_permalink(wc_get_page_id('checkout'))
        );

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error('API সংযুক্ত নয়');
        }

        $result = $api->post('/api/v1/sms/send', [
            'phone'   => $row->phone,
            'message' => $message,
            'purpose' => 'recovery',
        ]);

        if (!empty($result['success']) && $result['success'] === true) {
            wp_send_json_success(['message' => 'SMS পাঠানো হয়েছে']);
        }

        wp_send_json_error(isset($result['error']) ? $result['error'] : 'SMS পাঠানো যায়নি');
    }

    /**
     * AJAX: Convert incomplete order to real WC order.
     */
    public function ajax_convert() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));

        if (!$row) {
            wp_send_json_error('রেকর্ড পাওয়া যায়নি');
        }

        $cart_items = json_decode($row->cart_data, true);
        if (empty($cart_items)) {
            wp_send_json_error('কার্ট ডেটা নেই');
        }

        // Create WC order
        $order = wc_create_order();
        $order->set_billing_first_name($row->name ?: '');
        $order->set_billing_phone($row->phone);
        $order->set_billing_address_1($row->address ?: '');
        $order->set_billing_city($row->city ?: '');

        foreach ($cart_items as $item) {
            // Try to find the product by stored product_id first, then by name
            $product = null;
            if (!empty($item['product_id'])) {
                $product = wc_get_product($item['product_id']);
            }
            if (!$product) {
                $products = wc_get_products([
                    'limit'  => 1,
                    'status' => 'publish',
                    's'      => $item['name'],
                ]);
                if (!empty($products)) {
                    $product = $products[0];
                }
            }
            if ($product) {
                $order->add_product($product, $item['qty']);
            } else {
                // Add as a fee/line item with name
                $fee = new WC_Order_Item_Fee();
                $fee->set_name($item['name']);
                $fee->set_total($item['price'] * $item['qty']);
                $order->add_item($fee);
            }
        }

        $order->calculate_totals();
        $order->set_status('pending');
        $order->add_order_note('Guardify: ইনকমপ্লিট অর্ডার থেকে কনভার্ট করা হয়েছে।');
        $order->save();

        // Mark as recovered
        $wpdb->update($this->table_name, ['status' => 'recovered'], ['id' => $id], ['%s'], ['%d']);

        wp_send_json_success([
            'message'  => 'অর্ডার তৈরি হয়েছে',
            'order_id' => $order->get_id(),
        ]);
    }

    /**
     * Get pending incomplete orders for admin page.
     */
    public static function get_pending($limit = 50, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    /**
     * Get count of pending records.
     */
    public static function get_pending_count() {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';
        return (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending')
        );
    }

    /**
     * Cleanup records older than 30 days.
     */
    public function cleanup() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                30
            )
        );
    }
}
