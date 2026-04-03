<?php
defined('ABSPATH') || exit;

/**
 * Guardify Fraud Detection — Tracks customer device fingerprints, IP addresses,
 * and phone numbers. Auto-blocks fraudulent customers based on DP ratio and
 * configurable rules. Supports manual block/unblock and advanced block rules
 * (block by IP, phone, or device ID).
 */
class Guardify_Fraud_Detection {

    private static $instance = null;
    private $table_name;
    private $blocks_table;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name   = $wpdb->prefix . 'guardify_fraud_tracking';
        $this->blocks_table = $wpdb->prefix . 'guardify_blocks';

        if (get_option('guardify_fraud_detection_enabled', 'no') !== 'yes') {
            return;
        }

        // Checkout tracking
        add_action('woocommerce_checkout_order_processed', [$this, 'track_order'], 10, 1);

        // Block checks — checkout + ALL pages
        add_action('woocommerce_checkout_process', [$this, 'check_blocked'], 5);
        add_action('wp', [$this, 'check_blocked_on_page']);
        add_action('wp_ajax_guardify_check_phone_blocked', [$this, 'ajax_check_phone_blocked']);
        add_action('wp_ajax_nopriv_guardify_check_phone_blocked', [$this, 'ajax_check_phone_blocked']);

        // Auto-block by DP ratio
        add_action('woocommerce_checkout_order_processed', [$this, 'auto_block_check'], 20, 1);

        // Auto-block by order count within time window
        add_action('woocommerce_checkout_order_processed', [$this, 'auto_block_order_count'], 25, 1);

        // WooCommerce order action — Block User
        add_filter('woocommerce_order_actions', [$this, 'add_block_user_order_action']);
        add_action('woocommerce_order_action_guardify_block_user', [$this, 'process_block_user_order_action']);

        // Frontend device tracking
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_guardify_track_visit', [$this, 'ajax_track_visit']);
        add_action('wp_ajax_nopriv_guardify_track_visit', [$this, 'ajax_track_visit']);

        // Admin actions
        add_action('wp_ajax_guardify_block_user', [$this, 'ajax_block_user']);
        add_action('wp_ajax_guardify_unblock_user', [$this, 'ajax_unblock_user']);
        add_action('wp_ajax_guardify_bulk_unblock', [$this, 'ajax_bulk_unblock']);
        add_action('wp_ajax_guardify_add_block_rule', [$this, 'ajax_add_block_rule']);
        add_action('wp_ajax_guardify_remove_block_rule', [$this, 'ajax_remove_block_rule']);
        add_action('wp_ajax_guardify_toggle_block_rule', [$this, 'ajax_toggle_block_rule']);

        // Export / Import
        add_action('wp_ajax_guardify_export_blocked_users', [$this, 'ajax_export_blocked_users']);
        add_action('wp_ajax_guardify_import_blocked_users', [$this, 'ajax_import_blocked_users']);
        add_action('wp_ajax_guardify_export_block_rules', [$this, 'ajax_export_block_rules']);
        add_action('wp_ajax_guardify_import_block_rules', [$this, 'ajax_import_block_rules']);
        add_action('wp_ajax_guardify_export_all_fraud_data', [$this, 'ajax_export_all_data']);
        add_action('wp_ajax_guardify_import_all_fraud_data', [$this, 'ajax_import_all_data']);
    }

    /**
     * Create DB tables on activation.
     */
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $tracking = $wpdb->prefix . 'guardify_fraud_tracking';
        dbDelta("CREATE TABLE {$tracking} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            phone VARCHAR(20) NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            order_ids TEXT NULL,
            is_blocked TINYINT(1) DEFAULT 0,
            block_reason TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY phone (phone),
            KEY is_blocked (is_blocked)
        ) {$charset};");

        $blocks = $wpdb->prefix . 'guardify_blocks';
        dbDelta("CREATE TABLE {$blocks} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            block_type VARCHAR(20) NOT NULL,
            block_value VARCHAR(255) NOT NULL,
            reason TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY type_value (block_type, block_value),
            KEY block_type (block_type),
            KEY is_active (is_active)
        ) {$charset};");
    }

    /**
     * Enqueue fraud detection frontend scripts.
     */
    public function enqueue_scripts() {
        if (!is_checkout() && !is_cart()) {
            return;
        }

        wp_enqueue_script(
            'guardify-fraud-detection',
            GUARDIFY_URL . 'assets/js/fraud-detection.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-fraud-detection', 'guardifyFraud', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_fraud_nonce'),
        ]);
    }

    /**
     * AJAX: Track frontend visit with device fingerprint.
     */
    public function ajax_track_visit() {
        check_ajax_referer('guardify_fraud_nonce', 'nonce');

        $device_id = isset($_POST['device_id']) ? sanitize_text_field(wp_unslash($_POST['device_id'])) : '';
        if (empty($device_id) || strlen($device_id) > 100) {
            wp_send_json_error('Invalid device ID');
        }

        $ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // Store device ID in a cookie for use during checkout
        setcookie('guardify_device_id', $device_id, time() + YEAR_IN_SECONDS, '/', '', is_ssl(), true);

        global $wpdb;

        // Check if this device was already tracked
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, ip_address FROM {$this->table_name} WHERE phone = %s",
            'device:' . $device_id
        ));

        if ($existing) {
            $wpdb->update($this->table_name, [
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'last_seen'  => current_time('mysql'),
            ], ['id' => $existing->id], ['%s', '%s', '%s'], ['%d']);
        } else {
            $wpdb->insert($this->table_name, [
                'phone'      => 'device:' . $device_id,
                'ip_address' => $ip,
                'user_agent' => $user_agent,
            ], ['%s', '%s', '%s']);
        }

        wp_send_json_success();
    }

    /**
     * Check if the current visitor is blocked — ALL frontend pages, not just checkout.
     * Checks: IP block rules, phone tracking table, device cookie link.
     */
    public function check_blocked_on_page() {
        if (is_admin()) {
            return;
        }

        // Skip order-received / thank-you pages to avoid blocking right after purchase
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            return;
        }
        if (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order')) {
            return;
        }

        $ip = $this->get_client_ip();
        $device_id = isset($_COOKIE['guardify_device_id']) ? sanitize_text_field(wp_unslash($_COOKIE['guardify_device_id'])) : '';
        $blocked_reason = '';

        global $wpdb;

        // 1. Check IP in block rules table
        if (!empty($ip)) {
            $ip_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->blocks_table}
                 WHERE is_active = 1 AND block_type = 'ip' AND block_value = %s",
                $ip
            ));
            if ($ip_blocked > 0) {
                $blocked_reason = 'IP ব্লক করা হয়েছে';
            }
        }

        // 2. Check IP in tracking table (is_blocked)
        if (empty($blocked_reason) && !empty($ip)) {
            $tracking_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT block_reason FROM {$this->table_name}
                 WHERE is_blocked = 1 AND ip_address = %s LIMIT 1",
                $ip
            ));
            if ($tracking_blocked) {
                $blocked_reason = $tracking_blocked;
            }
        }

        // 3. Check device cookie link
        if (empty($blocked_reason) && !empty($device_id)) {
            $device_blocked = $wpdb->get_var($wpdb->prepare(
                "SELECT is_blocked FROM {$this->table_name} WHERE phone = %s",
                'device:' . $device_id
            ));
            if ($device_blocked) {
                $blocked_reason = 'ডিভাইস ব্লক করা হয়েছে';
            }
        }

        if (!empty($blocked_reason)) {
            add_action('wp_footer', [$this, 'render_blocked_popup']);
        }
    }

    /**
     * Render non-dismissible blocked user popup on frontend.
     */
    public function render_blocked_popup() {
        $title   = get_option('guardify_blocked_user_title', 'অর্ডার ব্লক করা হয়েছে');
        $message = get_option('guardify_blocked_user_message', 'নিরাপত্তার কারণে এই ডিভাইস/IP থেকে অর্ডার প্লেস করা ব্লক করা হয়েছে। সমস্যা থাকলে গ্রাহকসেবায় যোগাযোগ করুন।');
        $support = get_option('guardify_fraud_support_number', '');
        ?>
        <div id="guardify-blocked-popup" class="gf-blocked-popup" style="display:flex;">
            <div class="gf-blocked-popup-overlay"></div>
            <div class="gf-blocked-popup-content">
                <div class="gf-blocked-popup-icon">🚫</div>
                <h3><?php echo esc_html($title); ?></h3>
                <p><?php echo esc_html($message); ?></p>
                <?php if (!empty($support)) : ?>
                <div style="margin-top:16px;">
                    <a href="tel:<?php echo esc_attr($support); ?>" style="display:inline-flex;align-items:center;gap:6px;padding:10px 24px;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;">
                        <span class="dashicons dashicons-phone"></span> কল করুন: <?php echo esc_html($support); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <style>
            .gf-blocked-popup { position:fixed; inset:0; z-index:99999; display:flex; align-items:center; justify-content:center; }
            .gf-blocked-popup-overlay { position:absolute; inset:0; background:rgba(0,0,0,0.6); }
            .gf-blocked-popup-content { position:relative; background:#fff; border-radius:12px; width:90%; max-width:420px; padding:32px; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.2); }
            .gf-blocked-popup-icon { font-size:48px; margin-bottom:16px; }
            .gf-blocked-popup-content h3 { margin:0 0 12px; font-size:20px; font-weight:700; color:#dc2626; }
            .gf-blocked-popup-content p { margin:0; font-size:14px; color:#4b5563; line-height:1.6; }
        </style>
        <script>
        jQuery(function($){
            // Disable place order and prevent checkout submission
            $('#place_order').prop('disabled', true).css('opacity', '0.5');
            $('form.checkout').on('submit', function(e){ e.preventDefault(); return false; });
            $(document.body).on('checkout_place_order', function(){ return false; });
            // Prevent popup dismissal
            $(document).on('click keydown', function(e){
                if ($('#guardify-blocked-popup').is(':visible') && $(e.target).closest('.gf-blocked-popup-content').length === 0) {
                    e.stopPropagation();
                    return false;
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Track order: Store phone + IP for fraud scoring.
     */
    public function track_order($order_id) {
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

        $ip = $this->get_client_ip();
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        // Save device fingerprint to order meta if available
        $device_id = isset($_COOKIE['guardify_device_id']) ? sanitize_text_field(wp_unslash($_COOKIE['guardify_device_id'])) : '';
        if (!empty($device_id)) {
            $order->update_meta_data('_guardify_device_id', $device_id);
            $order->save();

            // Link device record to this phone in tracking table
            global $wpdb;
            $device_record = $wpdb->get_row($wpdb->prepare(
                "SELECT id, is_blocked FROM {$this->table_name} WHERE phone = %s",
                'device:' . $device_id
            ));
            if ($device_record && $device_record->is_blocked) {
                // If device was previously blocked, block this phone too
                $this->block_phone($phone, 'ব্লক করা ডিভাইস থেকে অর্ডার');
            }
        }

        // Save IP to order meta
        $order->update_meta_data('_guardify_ip_address', $ip);
        $order->save();

        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, order_ids FROM {$this->table_name} WHERE phone = %s",
            $phone
        ));

        if ($existing) {
            $order_ids = $existing->order_ids ? $existing->order_ids . ',' . $order_id : (string) $order_id;
            $wpdb->update($this->table_name, [
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'order_ids'  => $order_ids,
                'last_seen'  => current_time('mysql'),
            ], ['id' => $existing->id], ['%s', '%s', '%s', '%s'], ['%d']);
        } else {
            $wpdb->insert($this->table_name, [
                'phone'      => $phone,
                'ip_address' => $ip,
                'user_agent' => $user_agent,
                'order_ids'  => (string) $order_id,
            ], ['%s', '%s', '%s', '%s']);
        }
    }

    /**
     * Check if customer is blocked at checkout — uses throw Exception for strong block.
     */
    public function check_blocked() {
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            return;
        }

        // Check phone block in tracking table
        global $wpdb;
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT is_blocked FROM {$this->table_name} WHERE phone = %s",
            $phone
        ));

        if ($blocked) {
            throw new \Exception('এই ফোন নম্বর থেকে অর্ডার ব্লক করা হয়েছে।');
        }

        // Check advanced block rules
        $ip = $this->get_client_ip();

        $rule_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->blocks_table}
             WHERE is_active = 1 AND (
                 (block_type = 'phone' AND block_value = %s)
                 OR (block_type = 'ip' AND block_value = %s)
             )",
            $phone, $ip
        ));

        if ($rule_blocked > 0) {
            throw new \Exception('এই অর্ডারটি নিরাপত্তার কারণে ব্লক করা হয়েছে।');
        }
    }

    /**
     * Auto-block: After order is placed, check DP ratio and auto-block if below threshold.
     */
    public function auto_block_check($order_id) {
        $threshold = (float) get_option('guardify_fraud_auto_block_dp', 0);
        if ($threshold <= 0) {
            return; // Auto-block disabled
        }

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

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        // Use transient cache for DP check (2 min TTL)
        $cache_key = 'gf_dp_' . md5($phone);
        $result = get_transient($cache_key);
        if (false === $result) {
            $result = $api->get('/api/v1/courier/dp-ratio', ['phone' => $phone]);
            if (isset($result['dp_ratio']) || isset($result['data']['dp_ratio'])) {
                set_transient($cache_key, $result, 2 * MINUTE_IN_SECONDS);
            }
        }

        // Normalize wrapped API response
        $d = isset($result['data']) ? $result['data'] : $result;

        if (!isset($d['dp_ratio'])) {
            return;
        }

        $dp    = (float) $d['dp_ratio'];
        $total = isset($d['total']) ? (int) $d['total'] : 0;

        // Skip if customer has no history (new customer)
        if ($total === 0) {
            return;
        }

        if ($dp < $threshold) {
            $this->block_phone($phone, sprintf('অটো-ব্লক: DP %.1f%% (থ্রেশোল্ড %.0f%%)', $dp, $threshold));
            $order->add_order_note(sprintf(
                'Guardify: অটো-ব্লক — DP রেশিও %.1f%% (থ্রেশোল্ড %.0f%%, মোট %d পার্সেল)',
                $dp, $threshold, $total
            ));
            $order->save();
        }
    }

    /**
     * Auto-block by order count within time window.
     * If a phone has placed >= X orders in Y hours, block it.
     */
    public function auto_block_order_count($order_id) {
        $enabled = get_option('guardify_fraud_auto_block_count_enabled', 'no');
        if ($enabled !== 'yes') {
            return;
        }

        $order_limit = absint(get_option('guardify_fraud_auto_block_order_limit', 3));
        $time_limit  = absint(get_option('guardify_fraud_auto_block_time_limit', 24));
        if ($order_limit < 1 || $time_limit < 1) {
            return;
        }

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

        // Check if already blocked
        global $wpdb;
        $already_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT is_blocked FROM {$this->table_name} WHERE phone = %s",
            $phone
        ));
        if ($already_blocked) {
            return;
        }

        // Count recent orders for this phone
        $phone_variants = [$phone];
        if (strpos($phone, '88') !== 0) {
            $phone_variants[] = '88' . $phone;
        }
        $phone_variants[] = '+88' . ltrim($phone, '0');

        $cutoff = current_time('timestamp') - ($time_limit * 3600);
        $recent_count = 0;

        foreach ($phone_variants as $p) {
            $orders = wc_get_orders([
                'billing_phone' => $p,
                'limit'         => $order_limit + 1,
                'orderby'       => 'date',
                'order'         => 'DESC',
            ]);

            foreach ($orders as $o) {
                $order_ts_utc = $o->get_date_created()->getTimestamp();
                $order_ts_wp  = $order_ts_utc + (get_option('gmt_offset') * 3600);
                if ($order_ts_wp >= $cutoff) {
                    $recent_count++;
                }
            }
        }

        if ($recent_count >= $order_limit) {
            $reason = sprintf(
                'অটো-ব্লক: %d ঘণ্টায় %d টি অর্ডার (সীমা: %d)',
                $time_limit, $recent_count, $order_limit
            );
            $this->block_phone($phone, $reason);
            $order->add_order_note('Guardify: ' . $reason);
            $order->save();
        }
    }

    /**
     * Add "Block User" action to WooCommerce order actions dropdown.
     */
    public function add_block_user_order_action($actions) {
        global $theorder;
        if ($theorder) {
            $phone = $theorder->get_billing_phone();
            if (!empty($phone)) {
                $actions['guardify_block_user'] = 'Guardify: ব্যবহারকারী ব্লক করুন (ফ্রড প্রোটেকশন)';
            }
        }
        return $actions;
    }

    /**
     * Process "Block User" order action.
     */
    public function process_block_user_order_action($order) {
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            $order->add_order_note('Guardify: ব্লক ব্যর্থ — ফোন নম্বর পাওয়া যায়নি।');
            return;
        }

        $this->block_phone($phone, 'অ্যাডমিন কর্তৃক অর্ডার অ্যাকশন থেকে ব্লক');

        // Also block the IP if available
        $ip = $order->get_meta('_guardify_ip_address');
        if (!empty($ip)) {
            global $wpdb;
            $wpdb->replace($this->blocks_table, [
                'block_type'  => 'ip',
                'block_value' => $ip,
                'reason'      => 'অর্ডার #' . $order->get_id() . ' থেকে ব্লক',
                'created_by'  => get_current_user_id(),
                'is_active'   => 1,
            ], ['%s', '%s', '%s', '%d', '%d']);
        }

        $order->add_order_note(sprintf(
            'Guardify: ফোন %s ব্লক করা হয়েছে। %s',
            $phone,
            (!empty($ip) ? 'IP ' . $ip . ' ও ব্লক করা হয়েছে।' : '')
        ));
    }

    /**
     * Block a phone number.
     */
    public function block_phone($phone, $reason = '') {
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE phone = %s",
            $phone
        ));

        if ($existing) {
            $wpdb->update($this->table_name, [
                'is_blocked'   => 1,
                'block_reason' => $reason,
            ], ['phone' => $phone], ['%d', '%s'], ['%s']);
        } else {
            $wpdb->insert($this->table_name, [
                'phone'        => $phone,
                'is_blocked'   => 1,
                'block_reason' => $reason,
            ], ['%s', '%d', '%s']);
        }
        return true;
    }

    /**
     * Unblock a phone number.
     */
    public function unblock_phone($phone) {
        global $wpdb;
        return $wpdb->update($this->table_name, [
            'is_blocked'   => 0,
            'block_reason' => null,
        ], ['phone' => $phone], ['%d', '%s'], ['%s']);
    }

    /**
     * AJAX: Check if phone is blocked (frontend).
     * Also checks advanced block rules for the phone.
     */
    public function ajax_check_phone_blocked() {
        check_ajax_referer('guardify_fraud_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            wp_send_json_success(['blocked' => false]);
        }

        global $wpdb;

        // Check tracking table
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT is_blocked FROM {$this->table_name} WHERE phone = %s",
            $phone
        ));

        if ($blocked) {
            wp_send_json_success(['blocked' => true]);
        }

        // Check advanced block rules (phone type)
        $rule_blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->blocks_table}
             WHERE is_active = 1 AND block_type = 'phone' AND block_value = %s",
            $phone
        ));

        wp_send_json_success(['blocked' => (bool) ($rule_blocked > 0)]);
    }

    /**
     * AJAX: Block user by phone (admin).
     */
    public function ajax_block_user() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $phone  = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : 'অ্যাডমিন দ্বারা ব্লক';

        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            wp_send_json_error('ফোন নম্বর প্রয়োজন');
        }

        $this->block_phone($phone, $reason);
        wp_send_json_success(['message' => 'ব্লক করা হয়েছে']);
    }

    /**
     * AJAX: Unblock user by phone (admin).
     */
    public function ajax_unblock_user() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        $this->unblock_phone($phone);
        wp_send_json_success(['message' => 'আনব্লক করা হয়েছে']);
    }

    /**
     * AJAX: Add advanced block rule.
     */
    public function ajax_add_block_rule() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $type   = isset($_POST['block_type']) ? sanitize_text_field(wp_unslash($_POST['block_type'])) : '';
        $value  = isset($_POST['block_value']) ? sanitize_text_field(wp_unslash($_POST['block_value'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field(wp_unslash($_POST['reason'])) : '';

        if (!in_array($type, ['phone', 'ip'], true) || empty($value)) {
            wp_send_json_error('ব্লক টাইপ ও ভ্যালু প্রয়োজন');
        }

        global $wpdb;
        $wpdb->replace($this->blocks_table, [
            'block_type'  => $type,
            'block_value' => $value,
            'reason'      => $reason,
            'created_by'  => get_current_user_id(),
            'is_active'   => 1,
        ], ['%s', '%s', '%s', '%d', '%d']);

        wp_send_json_success(['message' => 'ব্লক রুল যোগ হয়েছে']);
    }

    /**
     * AJAX: Remove advanced block rule.
     */
    public function ajax_remove_block_rule() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if ($id) {
            global $wpdb;
            $wpdb->delete($this->blocks_table, ['id' => $id], ['%d']);
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Bulk unblock multiple phones (admin).
     */
    public function ajax_bulk_unblock() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $phones = isset($_POST['phones']) ? array_map('sanitize_text_field', (array) $_POST['phones']) : [];
        if (empty($phones)) {
            wp_send_json_error('কোনো ফোন নম্বর নির্বাচন করা হয়নি');
        }

        $count = 0;
        foreach ($phones as $phone) {
            $phone = preg_replace('/[\s\-]/', '', $phone);
            $phone = preg_replace('/^\+?88/', '', $phone);
            if (!empty($phone) && $this->unblock_phone($phone) !== false) {
                $count++;
            }
        }

        wp_send_json_success([
            'message' => sprintf('%d জন ব্যবহারকারী আনব্লক করা হয়েছে', $count),
        ]);
    }

    /**
     * AJAX: Toggle advanced block rule active/inactive.
     */
    public function ajax_toggle_block_rule() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        if (!$id) {
            wp_send_json_error('ID প্রয়োজন');
        }

        global $wpdb;
        $current = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$this->blocks_table} WHERE id = %d",
            $id
        ));

        if ($current === null) {
            wp_send_json_error('রুল পাওয়া যায়নি');
        }

        $new_status = $current ? 0 : 1;
        $wpdb->update($this->blocks_table, ['is_active' => $new_status], ['id' => $id], ['%d'], ['%d']);

        wp_send_json_success([
            'message'   => $new_status ? 'রুল সক্রিয় করা হয়েছে' : 'রুল নিষ্ক্রিয় করা হয়েছে',
            'is_active' => $new_status,
        ]);
    }

    /* ───── Export / Import ───── */

    /**
     * AJAX: Export blocked users as CSV.
     */
    public function ajax_export_blocked_users() {
        check_ajax_referer('guardify_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT phone, ip_address, block_reason, order_ids, created_at, last_seen
             FROM {$this->table_name} WHERE is_blocked = 1 ORDER BY last_seen DESC",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="guardify_blocked_users_' . gmdate('Y-m-d_H-i-s') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['phone', 'ip_address', 'block_reason', 'order_ids', 'created_at', 'last_seen']);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    /**
     * AJAX: Import blocked users from CSV.
     */
    public function ajax_import_blocked_users() {
        check_ajax_referer('guardify_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('ফাইল আপলোড ব্যর্থ');
        }

        $path = $_FILES['import_file']['tmp_name'];
        if (!is_readable($path)) {
            wp_send_json_error('ফাইল পড়া যাচ্ছে না');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            wp_send_json_error('ফাইল খোলা যাচ্ছে না');
        }

        // Skip header
        fgetcsv($handle);

        $imported = 0;
        $skipped  = 0;

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 1) {
                $skipped++;
                continue;
            }

            $phone  = sanitize_text_field($data[0]);
            $reason = isset($data[2]) ? sanitize_textarea_field($data[2]) : 'CSV ইম্পোর্ট';

            if (empty($phone)) {
                $skipped++;
                continue;
            }

            $phone = preg_replace('/[\s\-]/', '', $phone);
            $phone = preg_replace('/^\+?88/', '', $phone);

            $this->block_phone($phone, $reason);
            $imported++;
        }
        fclose($handle);

        wp_send_json_success([
            'message' => sprintf('ইম্পোর্ট সম্পন্ন। যোগ: %d, বাদ: %d', $imported, $skipped),
        ]);
    }

    /**
     * AJAX: Export advanced block rules as CSV.
     */
    public function ajax_export_block_rules() {
        check_ajax_referer('guardify_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT block_type, block_value, reason, is_active, created_at
             FROM {$this->blocks_table} ORDER BY created_at DESC",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="guardify_block_rules_' . gmdate('Y-m-d_H-i-s') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['block_type', 'block_value', 'reason', 'is_active', 'created_at']);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);
        exit;
    }

    /**
     * AJAX: Import advanced block rules from CSV.
     */
    public function ajax_import_block_rules() {
        check_ajax_referer('guardify_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('ফাইল আপলোড ব্যর্থ');
        }

        $path = $_FILES['import_file']['tmp_name'];
        if (!is_readable($path)) {
            wp_send_json_error('ফাইল পড়া যাচ্ছে না');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            wp_send_json_error('ফাইল খোলা যাচ্ছে না');
        }

        // Skip header
        fgetcsv($handle);

        $imported = 0;
        $skipped  = 0;

        global $wpdb;

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) < 2) {
                $skipped++;
                continue;
            }

            $type   = sanitize_text_field($data[0]);
            $value  = sanitize_text_field($data[1]);
            $reason = isset($data[2]) ? sanitize_textarea_field($data[2]) : '';

            if (!in_array($type, ['phone', 'ip'], true) || empty($value)) {
                $skipped++;
                continue;
            }

            if ($type === 'phone') {
                $value = preg_replace('/[^0-9+]/', '', $value);
            }

            $wpdb->replace($this->blocks_table, [
                'block_type'  => $type,
                'block_value' => $value,
                'reason'      => $reason,
                'created_by'  => get_current_user_id(),
                'is_active'   => 1,
            ], ['%s', '%s', '%s', '%d', '%d']);
            $imported++;
        }
        fclose($handle);

        wp_send_json_success([
            'message' => sprintf('ইম্পোর্ট সম্পন্ন। যোগ: %d, বাদ: %d', $imported, $skipped),
        ]);
    }

    /**
     * AJAX: Export all fraud data (blocked users + block rules) as CSV.
     */
    public function ajax_export_all_data() {
        check_ajax_referer('guardify_export', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_die('Permission denied');
        }

        global $wpdb;

        $blocked_users = $wpdb->get_results(
            "SELECT phone, ip_address, block_reason, order_ids, created_at, last_seen
             FROM {$this->table_name} WHERE is_blocked = 1 ORDER BY last_seen DESC",
            ARRAY_A
        );

        $block_rules = $wpdb->get_results(
            "SELECT block_type, block_value, reason, is_active, created_at
             FROM {$this->blocks_table} ORDER BY created_at DESC",
            ARRAY_A
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="guardify_all_fraud_data_' . gmdate('Y-m-d_H-i-s') . '.csv"');

        $out = fopen('php://output', 'w');

        fputcsv($out, ['=== ব্লক রুল ===']);
        fputcsv($out, ['block_type', 'block_value', 'reason', 'is_active', 'created_at']);
        foreach ($block_rules as $r) {
            fputcsv($out, $r);
        }

        fputcsv($out, ['']);
        fputcsv($out, ['=== ব্লক করা ব্যবহারকারী ===']);
        fputcsv($out, ['phone', 'ip_address', 'block_reason', 'order_ids', 'created_at', 'last_seen']);
        foreach ($blocked_users as $r) {
            fputcsv($out, $r);
        }

        fclose($out);
        exit;
    }

    /**
     * AJAX: Import all fraud data (blocked users + block rules) from CSV.
     */
    public function ajax_import_all_data() {
        check_ajax_referer('guardify_import', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('ফাইল আপলোড ব্যর্থ');
        }

        $path = $_FILES['import_file']['tmp_name'];
        if (!is_readable($path)) {
            wp_send_json_error('ফাইল পড়া যাচ্ছে না');
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            wp_send_json_error('ফাইল খোলা যাচ্ছে না');
        }

        $section     = '';
        $rules_count = 0;
        $users_count = 0;
        $skipped     = 0;

        global $wpdb;

        while (($data = fgetcsv($handle)) !== false) {
            if (empty($data) || empty(trim($data[0]))) {
                continue;
            }

            $first = trim($data[0]);

            if (strpos($first, '=== ব্লক রুল ===') !== false || strpos($first, '=== BLOCK RULES ===') !== false) {
                $section = 'rules';
                fgetcsv($handle); // skip header
                continue;
            }
            if (strpos($first, '=== ব্লক করা ব্যবহারকারী ===') !== false || strpos($first, '=== BLOCKED USERS ===') !== false) {
                $section = 'users';
                fgetcsv($handle); // skip header
                continue;
            }

            if ($section === 'rules') {
                if (count($data) < 2) { $skipped++; continue; }
                $type   = sanitize_text_field($data[0]);
                $value  = sanitize_text_field($data[1]);
                $reason = isset($data[2]) ? sanitize_textarea_field($data[2]) : '';

                if (!in_array($type, ['phone', 'ip'], true) || empty($value)) {
                    $skipped++;
                    continue;
                }

                $wpdb->replace($this->blocks_table, [
                    'block_type'  => $type,
                    'block_value' => $value,
                    'reason'      => $reason,
                    'created_by'  => get_current_user_id(),
                    'is_active'   => 1,
                ], ['%s', '%s', '%s', '%d', '%d']);
                $rules_count++;

            } elseif ($section === 'users') {
                if (count($data) < 1) { $skipped++; continue; }
                $phone  = sanitize_text_field($data[0]);
                $reason = isset($data[2]) ? sanitize_textarea_field($data[2]) : 'CSV ইম্পোর্ট';

                if (empty($phone)) { $skipped++; continue; }
                $phone = preg_replace('/[\s\-]/', '', $phone);
                $phone = preg_replace('/^\+?88/', '', $phone);

                $this->block_phone($phone, $reason);
                $users_count++;
            }
        }
        fclose($handle);

        wp_send_json_success([
            'message' => sprintf(
                'ইম্পোর্ট সম্পন্ন। ব্লক রুল: %d, ব্যবহারকারী: %d, বাদ: %d',
                $rules_count, $users_count, $skipped
            ),
        ]);
    }

    /**
     * Get blocked users list for admin page.
     */
    public static function get_blocked_users($limit = 50, $offset = 0) {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_fraud_tracking';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE is_blocked = 1 ORDER BY last_seen DESC LIMIT %d OFFSET %d",
            $limit,
            $offset
        ));
    }

    /**
     * Get advanced block rules (including inactive).
     */
    public static function get_block_rules($limit = 50) {
        global $wpdb;
        $table = $wpdb->prefix . 'guardify_blocks';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ));
    }

    /**
     * Get client IP address.
     */
    private function get_client_ip() {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
