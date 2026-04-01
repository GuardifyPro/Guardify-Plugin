<?php
defined('ABSPATH') || exit;

/**
 * Guardify Incomplete Orders — Captures checkout form data before order placement.
 * Server-side + client-side capture, configurable cooldown, bulk actions, export, convert.
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

        // Server-side capture when checkout review updates
        add_action('woocommerce_checkout_update_order_review', [$this, 'capture_from_checkout_review']);

        // Client-side AJAX capture (logged-in + guest)
        add_action('wp_ajax_guardify_store_incomplete', [$this, 'ajax_store']);
        add_action('wp_ajax_nopriv_guardify_store_incomplete', [$this, 'ajax_store']);

        // Frontend script on checkout
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Mark recovered on order completion
        add_action('woocommerce_thankyou', [$this, 'mark_recovered']);
        add_action('woocommerce_payment_complete', [$this, 'mark_recovered']);
        add_action('woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 3);

        // Admin AJAX handlers
        add_action('wp_ajax_guardify_delete_incomplete', [$this, 'ajax_delete']);
        add_action('wp_ajax_guardify_bulk_delete_incomplete', [$this, 'ajax_bulk_delete']);
        add_action('wp_ajax_guardify_send_recovery_sms', [$this, 'ajax_send_sms']);
        add_action('wp_ajax_guardify_convert_incomplete', [$this, 'ajax_convert']);
        add_action('wp_ajax_guardify_bulk_convert_incomplete', [$this, 'ajax_bulk_convert']);
        add_action('wp_ajax_guardify_export_incomplete', [$this, 'ajax_export']);

        // Daily cleanup
        if (!wp_next_scheduled('guardify_cleanup_incomplete')) {
            wp_schedule_event(time(), 'daily', 'guardify_cleanup_incomplete');
        }
        add_action('guardify_cleanup_incomplete', [$this, 'cleanup']);
    }

    /* --- Database -------------------------------------------------- */

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'guardify_incomplete_orders';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NULL,
            phone VARCHAR(20) NOT NULL,
            email VARCHAR(255) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            country VARCHAR(100) NULL,
            postcode VARCHAR(20) NULL,
            cart_data LONGTEXT NULL,
            cart_total DECIMAL(10,2) NULL,
            status ENUM('pending','recovered','expired') DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add columns if upgrading from older schema
        $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
        $adds = [];
        if (!in_array('email', $cols, true))    $adds[] = "ADD COLUMN email VARCHAR(255) NULL AFTER phone";
        if (!in_array('state', $cols, true))    $adds[] = "ADD COLUMN state VARCHAR(100) NULL AFTER city";
        if (!in_array('country', $cols, true))  $adds[] = "ADD COLUMN country VARCHAR(100) NULL AFTER state";
        if (!in_array('postcode', $cols, true)) $adds[] = "ADD COLUMN postcode VARCHAR(20) NULL AFTER country";
        if (!empty($adds)) {
            $wpdb->query("ALTER TABLE {$table} " . implode(', ', $adds));
        }
    }

    /* --- Phone Helpers --------------------------------------------- */

    private function normalize_phone($phone) {
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);
        return $phone;
    }

    private function validate_phone($phone) {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }

    private function phone_variations($phone) {
        $normalized = $this->normalize_phone($phone);
        return [$normalized, '88' . $normalized, '+88' . $normalized];
    }

    /* --- Cooldown -------------------------------------------------- */

    private function is_in_cooldown($phone) {
        if (get_option('guardify_incomplete_cooldown_enabled', 'yes') !== 'yes') {
            return false;
        }

        $minutes = max(5, (int) get_option('guardify_incomplete_cooldown', 30));
        $phone_hash = md5($phone);

        // Check cookie
        if (isset($_COOKIE['gf_completed_' . $phone_hash])) {
            return true;
        }

        global $wpdb;
        $variations   = $this->phone_variations($phone);
        $placeholders = implode(',', array_fill(0, count($variations), '%s'));
        $cutoff       = gmdate('Y-m-d H:i:s', strtotime('-' . $minutes . ' minutes', current_time('timestamp')));

        // Check WC orders (HPOS compatible via wc_orders table)
        $wc_table = $wpdb->prefix . 'wc_orders';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wc_table}'") === $wc_table) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wc_table}
                 WHERE billing_phone IN ({$placeholders})
                 AND status IN ('wc-completed','wc-processing','wc-on-hold')
                 AND date_created_gmt >= %s",
                array_merge($variations, [$cutoff])
            ));
            if ($count > 0) return true;
        }

        // Check recovered entries
        $recovered = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name}
             WHERE phone IN ({$placeholders})
             AND status = 'recovered'
             AND created_at > %s",
            array_merge($variations, [$cutoff])
        ));

        return $recovered > 0;
    }

    private function set_cooldown_cookie($phone) {
        if (get_option('guardify_incomplete_cooldown_enabled', 'yes') !== 'yes') {
            return;
        }
        $minutes    = max(5, (int) get_option('guardify_incomplete_cooldown', 30));
        $expiration = time() + ($minutes * 60);
        $phone_hash = md5($phone);
        $secure     = is_ssl();

        setcookie('gf_completed_' . $phone_hash, '1', $expiration, COOKIEPATH, COOKIE_DOMAIN, $secure, true);
        $_COOKIE['gf_completed_' . $phone_hash] = '1';
        setcookie('gf_completed_' . $phone_hash, '1', $expiration, '/', COOKIE_DOMAIN, $secure, true);
    }

    /* --- Cart Data Collection -------------------------------------- */

    private function collect_cart_data() {
        if (!function_exists('WC') || !isset(WC()->cart)) {
            return ['items' => [], 'total' => 0];
        }

        $items = [];
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product      = $cart_item['data'];
            $product_name = $product->get_name();
            $variation_id = 0;
            $variation_attributes = [];

            if (!empty($cart_item['variation_id']) && $cart_item['variation_id'] > 0) {
                $variation_id = $cart_item['variation_id'];
                if (!empty($cart_item['variation'])) {
                    foreach ($cart_item['variation'] as $att_key => $att_value) {
                        $taxonomy  = str_replace('attribute_', '', $att_key);
                        $term_name = $att_value;
                        if (taxonomy_exists($taxonomy)) {
                            $term = get_term_by('slug', $att_value, $taxonomy);
                            if ($term && !is_wp_error($term)) {
                                $term_name = $term->name;
                            }
                        }
                        $variation_attributes[] = wc_attribute_label($taxonomy) . ': ' . $term_name;
                    }
                }
                if (!empty($variation_attributes)) {
                    $product_name .= ' (' . implode(', ', $variation_attributes) . ')';
                }
            }

            $items[] = [
                'product_id'           => $cart_item['product_id'],
                'variation_id'         => $variation_id,
                'name'                 => $product_name,
                'price'                => $product->get_price(),
                'quantity'             => $cart_item['quantity'],
                'variation_attributes' => $variation_attributes,
            ];
        }

        return [
            'items' => $items,
            'total' => WC()->cart->get_total('edit'),
        ];
    }

    /* --- Server-side Capture (checkout_update_order_review) --------- */

    public function capture_from_checkout_review($post_data) {
        global $wpdb;
        parse_str($post_data, $data);

        $phone = isset($data['billing_phone']) ? sanitize_text_field($data['billing_phone']) : '';
        $phone = $this->normalize_phone($phone);

        if (!$this->validate_phone($phone)) {
            return;
        }

        if ($this->is_in_cooldown($phone)) {
            return;
        }

        $cart = $this->collect_cart_data();
        $cart_json = !empty($cart['items']) ? wp_json_encode($cart['items']) : '';

        $name     = $this->sanitize_field($data, 'billing_first_name');
        $email    = isset($data['billing_email']) ? sanitize_email($data['billing_email']) : '';
        $address  = $this->sanitize_field($data, 'billing_address_1');
        $city     = $this->sanitize_field($data, 'billing_city');
        $state    = $this->sanitize_field($data, 'billing_state');
        $country  = $this->sanitize_field($data, 'billing_country');
        $postcode = $this->sanitize_field($data, 'billing_postcode');

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table_name} WHERE phone = %s AND status = 'pending'",
            $phone
        ));

        if (!$existing) {
            $wpdb->insert($this->table_name, [
                'name'       => $name,
                'phone'      => $phone,
                'email'      => $email,
                'address'    => $address,
                'city'       => $city,
                'state'      => $state,
                'country'    => $country,
                'postcode'   => $postcode,
                'cart_data'  => $cart_json,
                'cart_total' => $cart['total'],
                'created_at' => current_time('mysql'),
            ]);
        } elseif (!empty($cart_json)) {
            $wpdb->update(
                $this->table_name,
                [
                    'name'       => $name,
                    'email'      => $email,
                    'address'    => $address,
                    'city'       => $city,
                    'state'      => $state,
                    'country'    => $country,
                    'postcode'   => $postcode,
                    'cart_data'  => $cart_json,
                    'cart_total' => $cart['total'],
                    'created_at' => current_time('mysql'),
                ],
                ['phone' => $phone, 'status' => 'pending']
            );
        }
    }

    private function sanitize_field($data, $key) {
        $val = isset($data[$key]) ? $data[$key] : '';
        if ($val === 'undefined' || $val === 'null') $val = '';
        return sanitize_text_field($val);
    }

    /* --- AJAX Client-side Capture ---------------------------------- */

    public function ajax_store() {
        check_ajax_referer('guardify_incomplete_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = $this->normalize_phone($phone);

        if (!$this->validate_phone($phone)) {
            wp_send_json_error('Invalid phone');
        }

        if ($this->is_in_cooldown($phone)) {
            wp_send_json_success(['status' => 'cooldown']);
        }

        // Rate limit: max 5 captures per phone per 10 minutes
        $throttle_key = 'gf_ic_' . $phone;
        $count = (int) get_transient($throttle_key);
        if ($count >= 5) {
            wp_send_json_error('Too many requests');
        }
        set_transient($throttle_key, $count + 1, 10 * MINUTE_IN_SECONDS);

        global $wpdb;

        $name     = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $address  = isset($_POST['address']) ? sanitize_textarea_field(wp_unslash($_POST['address'])) : '';
        $city     = isset($_POST['city']) ? sanitize_text_field(wp_unslash($_POST['city'])) : '';
        $state    = isset($_POST['state']) ? sanitize_text_field(wp_unslash($_POST['state'])) : '';
        $country  = isset($_POST['country']) ? sanitize_text_field(wp_unslash($_POST['country'])) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field(wp_unslash($_POST['postcode'])) : '';

        $cart = $this->collect_cart_data();
        $cart_json = !empty($cart['items']) ? wp_json_encode($cart['items']) : '';

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE phone = %s AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
            $phone
        ));

        $row = [
            'name'       => $name,
            'email'      => $email,
            'address'    => $address,
            'city'       => $city,
            'state'      => $state,
            'country'    => $country,
            'postcode'   => $postcode,
            'cart_data'  => $cart_json,
            'cart_total' => $cart['total'],
            'created_at' => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($this->table_name, $row, ['id' => $existing]);
            wp_send_json_success(['status' => 'existing']);
        } else {
            $row['phone'] = $phone;
            $wpdb->insert($this->table_name, $row);
            wp_send_json_success(['status' => 'new']);
        }
    }

    /* --- Frontend Script ------------------------------------------- */

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

    /* --- Mark Recovered -------------------------------------------- */

    public function mark_recovered($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $phone = $this->normalize_phone($order->get_billing_phone());
        if (empty($phone)) return;

        global $wpdb;
        $wpdb->update(
            $this->table_name,
            ['status' => 'recovered'],
            ['phone' => $phone, 'status' => 'pending'],
            ['%s'],
            ['%s', '%s']
        );

        $this->set_cooldown_cookie($phone);
    }

    public function handle_status_change($order_id, $old_status, $new_status) {
        if (in_array($new_status, ['processing', 'completed', 'on-hold'], true)) {
            $this->mark_recovered($order_id);
        }
    }

    /* --- Admin: Single Delete -------------------------------------- */

    public function ajax_delete() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $id = absint($_POST['id'] ?? 0);
        if ($id) {
            global $wpdb;
            $wpdb->delete($this->table_name, ['id' => $id], ['%d']);
        }
        wp_send_json_success();
    }

    /* --- Admin: Bulk Delete ---------------------------------------- */

    public function ajax_bulk_delete() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $ids = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
        $ids = array_filter($ids);
        if (empty($ids)) wp_send_json_error('কিছু সিলেক্ট করুন');

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE id IN ({$placeholders})",
            ...$ids
        ));

        wp_send_json_success(['deleted' => count($ids)]);
    }

    /* --- Admin: Send Recovery SMS ---------------------------------- */

    public function ajax_send_sms() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $phone   = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (empty($phone) || empty($message)) {
            // Fallback: single-row SMS by ID (legacy)
            $id = absint($_POST['id'] ?? 0);
            if (!$id) wp_send_json_error('ফোন ও মেসেজ আবশ্যক');

            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
            if (!$row) wp_send_json_error('রেকর্ড পাওয়া যায়নি');

            $phone   = $row->phone;
            $message = $this->build_sms_message($row);
        } else {
            // Replace placeholders in user-edited message
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE phone = %s AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
                $this->normalize_phone($phone)
            ));
            $message = $this->replace_sms_placeholders($message, $row);
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error('API সংযুক্ত নয়');
        }

        $result = $api->post('/api/v1/sms/send', [
            'phone'   => $phone,
            'message' => mb_convert_encoding($message, 'UTF-8', 'auto'),
            'purpose' => 'recovery',
        ]);

        if (!empty($result['success']) && $result['success'] === true) {
            wp_send_json_success(['message' => 'SMS পাঠানো হয়েছে']);
        }

        wp_send_json_error(isset($result['error']) ? $result['error'] : 'SMS পাঠানো যায়নি');
    }

    private function build_sms_message($row) {
        $site = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $checkout_url = get_permalink(wc_get_page_id('checkout'));
        return sprintf(
            "আসসালামু আলাইকুম %s,\nআপনি %s থেকে অর্ডার সম্পন্ন করেননি। আপনার কার্টে পণ্য অপেক্ষা করছে!\nএখনই অর্ডার করুন: %s\nধন্যবাদ",
            $row->name ?: 'গ্রাহক',
            $site,
            $checkout_url
        );
    }

    private function replace_sms_placeholders($message, $row = null) {
        $product_names = '';
        $order_total   = 0;

        if ($row && !empty($row->cart_data)) {
            $cart = json_decode($row->cart_data, true);
            if (is_array($cart)) {
                $names = array_column($cart, 'name');
                $product_names = implode(', ', array_slice($names, 0, 3));
                foreach ($cart as $item) {
                    $order_total += ($item['price'] ?? 0) * ($item['quantity'] ?? 1);
                }
            }
        }

        return str_replace(
            ['{customer_name}', '{product_name}', '{order_total}', '{siteurl}'],
            [
                ($row && $row->name) ? $row->name : 'গ্রাহক',
                $product_names ?: 'আপনার পণ্য',
                '৳' . number_format($order_total),
                get_site_url(),
            ],
            $message
        );
    }

    /* --- Admin: Convert to WC Order -------------------------------- */

    public function ajax_convert() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $id     = absint($_POST['id'] ?? 0);
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';

        $valid_statuses = ['pending', 'processing', 'completed', 'on-hold'];
        if (!in_array($status, $valid_statuses, true)) $status = 'pending';

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
        if (!$row) wp_send_json_error('রেকর্ড পাওয়া যায়নি');

        $order_id = $this->create_wc_order($row, $status);
        if (is_wp_error($order_id)) {
            wp_send_json_error($order_id->get_error_message());
        }

        $wpdb->update($this->table_name, ['status' => 'recovered'], ['id' => $id], ['%s'], ['%d']);

        wp_send_json_success([
            'message'  => 'অর্ডার #' . $order_id . ' তৈরি হয়েছে',
            'order_id' => $order_id,
        ]);
    }

    /* --- Admin: Bulk Convert --------------------------------------- */

    public function ajax_bulk_convert() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error('Unauthorized');

        $ids    = isset($_POST['ids']) ? array_map('absint', (array) $_POST['ids']) : [];
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : 'pending';
        $ids    = array_filter($ids);

        if (empty($ids)) wp_send_json_error('কিছু সিলেক্ট করুন');

        $valid_statuses = ['pending', 'processing', 'completed', 'on-hold'];
        if (!in_array($status, $valid_statuses, true)) $status = 'pending';

        global $wpdb;
        $success = 0;
        $errors  = 0;

        foreach ($ids as $id) {
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
            if (!$row) { $errors++; continue; }

            $order_id = $this->create_wc_order($row, $status);
            if (is_wp_error($order_id)) { $errors++; continue; }

            $wpdb->update($this->table_name, ['status' => 'recovered'], ['id' => $id], ['%s'], ['%d']);
            $success++;
        }

        wp_send_json_success([
            'message'       => $success . ' টি অর্ডার তৈরি হয়েছে' . ($errors ? ', ' . $errors . ' টি ব্যর্থ' : ''),
            'success_count' => $success,
            'error_count'   => $errors,
        ]);
    }

    private function create_wc_order($row, $status = 'pending') {
        $cart_items = json_decode($row->cart_data, true);
        if (empty($cart_items)) {
            return new WP_Error('no_cart', 'কার্ট ডেটা নেই');
        }

        try {
            $order = wc_create_order();

            foreach ($cart_items as $item) {
                $product_id   = !empty($item['product_id']) ? (int) $item['product_id'] : 0;
                $variation_id = !empty($item['variation_id']) ? (int) $item['variation_id'] : 0;
                $quantity     = !empty($item['quantity']) ? (int) $item['quantity'] : 1;

                if ($variation_id > 0) {
                    $variation = wc_get_product($variation_id);
                    if ($variation) {
                        $variation_data = [];
                        if (!empty($item['variation_attributes']) && is_array($item['variation_attributes'])) {
                            foreach ($item['variation_attributes'] as $attribute) {
                                $parts = explode(': ', $attribute);
                                if (count($parts) === 2) {
                                    $variation_data['attribute_' . sanitize_title($parts[0])] = sanitize_title($parts[1]);
                                }
                            }
                        }
                        $order->add_product($variation, $quantity, $variation_data);
                        continue;
                    }
                }

                $product = $product_id ? wc_get_product($product_id) : null;
                if (!$product && !empty($item['name'])) {
                    $found = wc_get_products(['limit' => 1, 'status' => 'publish', 's' => $item['name']]);
                    if (!empty($found)) $product = $found[0];
                }
                if ($product) {
                    $order->add_product($product, $quantity);
                } else {
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name($item['name'] ?? 'পণ্য');
                    $fee->set_total(($item['price'] ?? 0) * $quantity);
                    $order->add_item($fee);
                }
            }

            $billing = [
                'first_name' => $row->name ?: '',
                'phone'      => $row->phone,
                'email'      => $row->email ?? '',
                'address_1'  => $row->address ?: '',
                'city'       => $row->city ?: '',
                'state'      => $row->state ?? '',
                'country'    => $row->country ?? ($row->city ? 'BD' : ''),
                'postcode'   => $row->postcode ?? '',
            ];
            $order->set_address($billing, 'billing');
            $order->set_address($billing, 'shipping');

            $order->set_payment_method('cod');
            $order->calculate_totals();
            $order->set_status($status);
            $order->add_order_note('Guardify: ইনকমপ্লিট অর্ডার থেকে কনভার্ট করা হয়েছে।');
            $order->save();

            return $order->get_id();
        } catch (Exception $e) {
            return new WP_Error('create_failed', $e->getMessage());
        }
    }

    /* --- Admin: CSV Export ----------------------------------------- */

    public function ajax_export() {
        check_ajax_referer('guardify_export_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_die('Unauthorized');

        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE status = 'pending' ORDER BY created_at DESC");

        if (empty($rows)) wp_die('কোনো ডেটা নেই');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="incomplete-orders-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, ['ID', 'নাম', 'ফোন', 'ইমেইল', 'ঠিকানা', 'শহর', 'পণ্য', 'মোট', 'সময়']);

        foreach ($rows as $row) {
            $products = '';
            $total    = 0;
            $cart     = json_decode($row->cart_data, true);
            if (is_array($cart)) {
                $names = [];
                foreach ($cart as $item) {
                    $qty     = $item['quantity'] ?? 1;
                    $names[] = ($item['name'] ?? '?') . ' (x' . $qty . ')';
                    $total  += ($item['price'] ?? 0) * $qty;
                }
                $products = implode('; ', $names);
            }

            fputcsv($out, [
                $row->id,
                $row->name,
                $row->phone,
                $row->email ?? '',
                implode(', ', array_filter([$row->address, $row->city, $row->state ?? ''])),
                $row->city,
                $products,
                number_format($total, 2),
                $row->created_at,
            ]);
        }

        fclose($out);
        exit;
    }

    /* --- Admin: Queries -------------------------------------------- */

    public static function get_pending($limit = 50, $offset = 0, $search = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = 'pending' AND (phone LIKE %s OR name LIKE %s) ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $like, $like, $limit, $offset
            ));
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $limit, $offset
        ));
    }

    public static function get_pending_count($search = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';

        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'pending' AND (phone LIKE %s OR name LIKE %s)",
                $like, $like
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    }

    public static function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_incomplete_orders';
        $row = $wpdb->get_row("SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
            SUM(CASE WHEN status = 'recovered' THEN 1 ELSE 0 END) AS recovered
            FROM {$table}
        ");
        return $row ?: (object) ['total' => 0, 'pending' => 0, 'recovered' => 0];
    }

    /* --- Cleanup --------------------------------------------------- */

    public function cleanup() {
        $days = (int) get_option('guardify_incomplete_retention', 30);
        if ($days === 0) return;

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
}
