<?php
/**
 * Plugin Name:       Guardify Pro
 * Plugin URI:        https://guardify.pro
 * Description:       ফ্রড ডিটেকশন, কুরিয়ার ইন্টেলিজেন্স, OTP ভেরিফিকেশন ও স্মার্ট অর্ডার ফিল্টারিং — বাংলাদেশের ই-কমার্সের জন্য।
 * Version:           0.1.0-beta
 * Author:            Tansiq Labs
 * Author URI:        https://tansiqlabs.com.bd
 * License:           Proprietary
 * Text Domain:       guardify-pro
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 */

defined('ABSPATH') || exit;

define('GUARDIFY_VERSION', '0.1.0-beta');
define('GUARDIFY_FILE', __FILE__);
define('GUARDIFY_PATH', plugin_dir_path(__FILE__));
define('GUARDIFY_URL', plugin_dir_url(__FILE__));
define('GUARDIFY_ENGINE_URL', 'https://api.guardify.pro');

// Autoload includes
require_once GUARDIFY_PATH . 'includes/class-guardify-activator.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-api.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-delivery.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-search.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-phone-validation.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-smart-filter.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-otp.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-vpn-block.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-repeat-blocker.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-custom-status.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-phone-history.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-report-column.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-order-notifications.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-incomplete-orders.php';
require_once GUARDIFY_PATH . 'includes/class-guardify-fraud-detection.php';

// ─── Auto-Update via GitHub Releases ─────────────────────────────
require_once GUARDIFY_PATH . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$guardifyUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/GuardifyPro/Guardify-Plugin/',
    GUARDIFY_FILE,
    'guardify-pro'
);
// Use tagged GitHub releases (not branch) for versioned updates
$guardifyUpdateChecker->getVcsApi()->enableReleaseAssets();

// Make checker instance globally accessible for manual checks
global $guardify_update_checker;
$guardify_update_checker = $guardifyUpdateChecker;

/**
 * Main plugin class.
 */
final class Guardify_Pro {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_activation_hook(GUARDIFY_FILE, ['Guardify_Activator', 'activate']);
        register_deactivation_hook(GUARDIFY_FILE, ['Guardify_Activator', 'deactivate']);

        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        // Check WooCommerce
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>Guardify Pro</strong> এর জন্য WooCommerce প্রয়োজন।</p></div>';
            });
            return;
        }

        // Initialize feature modules
        Guardify_Delivery::get_instance();
        Guardify_Phone_Validation::get_instance();
        Guardify_Smart_Filter::get_instance();
        Guardify_OTP::get_instance();
        Guardify_VPN_Block::get_instance();
        Guardify_Repeat_Blocker::get_instance();
        Guardify_Custom_Status::get_instance();
        Guardify_Phone_History::get_instance();
        Guardify_Report_Column::get_instance();
        Guardify_Order_Notifications::get_instance();
        Guardify_Incomplete_Orders::get_instance();
        Guardify_Fraud_Detection::get_instance();
        Guardify_Search::get_instance();

        // Admin menu
        add_action('admin_menu', [$this, 'register_menu']);

        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_guardify_connect', [$this, 'ajax_connect']);
        add_action('wp_ajax_guardify_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_guardify_status', [$this, 'ajax_status']);
        add_action('wp_ajax_guardify_save_settings', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_guardify_support_ticket', [$this, 'ajax_support_ticket']);
        add_action('wp_ajax_guardify_check_update', [$this, 'ajax_check_update']);
        add_action('wp_ajax_guardify_export_blocked', [$this, 'ajax_export_blocked']);
        add_action('wp_ajax_guardify_export_rules', [$this, 'ajax_export_rules']);
        add_action('wp_ajax_guardify_import_blocked', [$this, 'ajax_import_blocked']);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    public function register_menu() {
        add_menu_page(
            'Guardify Pro',
            'Guardify Pro',
            'manage_woocommerce',
            'guardify-pro',
            [$this, 'render_settings_page'],
            'dashicons-shield',
            56
        );

        add_submenu_page(
            'guardify-pro',
            'সেটিংস',
            'সেটিংস',
            'manage_woocommerce',
            'guardify-pro',
            [$this, 'render_settings_page']
        );

        add_submenu_page(
            'guardify-pro',
            'ফোন সার্চ',
            'ফোন সার্চ',
            'manage_woocommerce',
            'guardify-search',
            [Guardify_Search::get_instance(), 'render_search_page']
        );

        add_submenu_page(
            'guardify-pro',
            'ফ্রড ম্যানেজমেন্ট',
            'ফ্রড ম্যানেজমেন্ট',
            'manage_woocommerce',
            'guardify-fraud',
            [$this, 'render_fraud_page']
        );

        add_submenu_page(
            'guardify-pro',
            'ইনকমপ্লিট অর্ডার',
            'ইনকমপ্লিট অর্ডার <span class="awaiting-mod">' . Guardify_Incomplete_Orders::get_pending_count() . '</span>',
            'manage_woocommerce',
            'guardify-incomplete',
            [$this, 'render_incomplete_page']
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }
        include GUARDIFY_PATH . 'templates/settings-page.php';
    }

    public function render_fraud_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }
        include GUARDIFY_PATH . 'templates/fraud-detection-page.php';
    }

    public function render_incomplete_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }
        include GUARDIFY_PATH . 'templates/incomplete-orders-page.php';
    }

    public function enqueue_admin_assets($hook) {
        $guardify_pages = ['guardify-pro', 'guardify-search', 'guardify-incomplete', 'guardify-fraud'];
        $is_guardify = false;
        foreach ($guardify_pages as $page) {
            if (strpos($hook, $page) !== false) {
                $is_guardify = true;
                break;
            }
        }
        if (!$is_guardify) {
            return;
        }

        wp_enqueue_style(
            'guardify-admin',
            GUARDIFY_URL . 'assets/css/admin.css',
            [],
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-admin',
            GUARDIFY_URL . 'assets/js/admin.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        // Hide non-Guardify admin notices on our pages
        add_action('admin_notices', function () {
            echo '<style>.notice:not(.guardify-notice) { display: none !important; }</style>';
        }, 0);

        wp_localize_script('guardify-admin', 'guardifyData', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('guardify_nonce'),
            'checkoutNonce' => wp_create_nonce('guardify_checkout_nonce'),
            'connected' => !empty(get_option('guardify_api_key', '')),
        ]);
    }

    public function register_rest_routes() {
        register_rest_route('guardify/v1', '/status', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_status'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
        ]);
    }

    public function rest_status() {
        $api = new Guardify_API();
        $status = $api->check_status();
        return rest_ensure_response($status);
    }

    // ─── AJAX Handlers ───────────────────────────────────────────────────

    public function ajax_connect() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $api_key    = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        $secret_key = isset($_POST['secret_key']) ? trim(wp_unslash($_POST['secret_key'])) : '';

        if (empty($api_key) || empty($secret_key) || !preg_match('/^[a-zA-Z0-9_\-]+$/', $secret_key)) {
            wp_send_json_error('API Key ও Secret Key প্রয়োজন।');
        }

        $api = new Guardify_API();
        $api->save_credentials($api_key, $secret_key);

        $result = $api->check_key();

        if (!empty($result['success']) && $result['success'] === true) {
            wp_send_json_success();
        }

        // Verification failed — clear credentials
        $api->clear_credentials();
        $error = isset($result['error']) ? $result['error'] : 'API কী যাচাই ব্যর্থ হয়েছে।';
        wp_send_json_error($error);
    }

    public function ajax_disconnect() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $api = new Guardify_API();
        $api->clear_credentials();
        wp_send_json_success();
    }

    public function ajax_support_ticket() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        if (empty($subject) || empty($message)) {
            wp_send_json_error('বিষয় ও বিস্তারিত আবশ্যক।');
        }

        $api = new Guardify_API();
        $result = $api->post('/api/v1/support/ticket', [
            'subject' => $subject,
            'message' => $message,
        ]);

        if (!empty($result['data']['id']) || !empty($result['id'])) {
            wp_send_json_success(['message' => 'টিকেট সফলভাবে পাঠানো হয়েছে।']);
        }

        $error = isset($result['error']) ? $result['error'] : 'টিকেট পাঠানো যায়নি।';
        wp_send_json_error($error);
    }

    public function ajax_status() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $api    = new Guardify_API();
        $result = $api->check_status();

        if (!empty($result['success']) && $result['success'] === true) {
            wp_send_json_success([
                'active' => true,
                'plan'   => isset($result['data']['plan']) ? $result['data']['plan'] : 'Free',
            ]);
        }

        wp_send_json_success(['active' => false]);
    }

    /**
     * AJAX: Manually trigger an update check.
     */
    public function ajax_check_update() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        global $guardify_update_checker;
        if (!$guardify_update_checker) {
            wp_send_json_error('আপডেট চেকার পাওয়া যায়নি।');
        }

        // Force-check ignoring cache
        $guardify_update_checker->resetUpdateState();
        $update = $guardify_update_checker->checkForUpdates();

        if (!empty($update)) {
            wp_send_json_success([
                'has_update'  => true,
                'new_version' => $update->version,
                'message'     => 'নতুন ভার্সন পাওয়া গেছে: ' . esc_html($update->version),
            ]);
        } else {
            wp_send_json_success([
                'has_update' => false,
                'message'    => 'আপনার প্লাগইন আপ-টু-ডেট আছে। (ভার্সন ' . GUARDIFY_VERSION . ')',
            ]);
        }
    }

    /**
     * AJAX: Export blocked users as CSV.
     */
    public function ajax_export_blocked() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'guardify_fraud_tracking';
        $rows = $wpdb->get_results("SELECT phone, ip_address, block_reason, last_seen FROM {$table} WHERE is_blocked = 1 ORDER BY last_seen DESC");

        $csv = "ফোন,IP,কারণ,সর্বশেষ\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s\n",
                $r->phone,
                $r->ip_address ?: '',
                str_replace(',', ';', $r->block_reason ?: ''),
                $r->last_seen ?: ''
            );
        }

        wp_send_json_success(['csv' => $csv]);
    }

    /**
     * AJAX: Export block rules as CSV.
     */
    public function ajax_export_rules() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'guardify_blocks';
        $rows = $wpdb->get_results("SELECT block_type, block_value, reason, created_at FROM {$table} WHERE is_active = 1 ORDER BY created_at DESC");

        $csv = "টাইপ,ভ্যালু,কারণ,তৈরির সময়\n";
        foreach ($rows as $r) {
            $csv .= sprintf(
                "%s,%s,%s,%s\n",
                $r->block_type,
                $r->block_value,
                str_replace(',', ';', $r->reason ?: ''),
                $r->created_at ?: ''
            );
        }

        wp_send_json_success(['csv' => $csv]);
    }

    /**
     * AJAX: Import blocked phones from JSON array.
     */
    public function ajax_import_blocked() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $raw = isset($_POST['phones']) ? wp_unslash($_POST['phones']) : '';
        $phones = json_decode($raw, true);
        if (!is_array($phones) || empty($phones)) {
            wp_send_json_error('কোনো ফোন নম্বর পাওয়া যায়নি');
        }

        $fraud = Guardify_Fraud_Detection::get_instance();
        $count = 0;
        foreach ($phones as $phone) {
            $phone = preg_replace('/[\s\-]/', '', sanitize_text_field($phone));
            $phone = preg_replace('/^\+?88/', '', $phone);
            if (!empty($phone) && preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $fraud->block_phone($phone, 'ইম্পোর্ট থেকে ব্লক');
                $count++;
            }
        }

        wp_send_json_success(['message' => $count . ' টি ফোন নম্বর ইম্পোর্ট ও ব্লক করা হয়েছে।']);
    }

    /**
     * AJAX: Save plugin feature settings.
     */
    public function ajax_save_settings() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        // Toggle options (yes/no checkboxes)
        $toggles = [
            'guardify_smart_filter_enabled',
            'guardify_otp_enabled',
            'guardify_vpn_block_enabled',
            'guardify_repeat_blocker_enabled',
            'guardify_fraud_detection_enabled',
            'guardify_sms_notifications_enabled',
            'guardify_incomplete_orders_enabled',
            'guardify_phone_history_enabled',
            'guardify_report_column_enabled',
        ];

        foreach ($toggles as $key) {
            $val = isset($_POST[$key]) && $_POST[$key] === 'yes' ? 'yes' : 'no';
            update_option($key, $val);
        }

        // Numeric / string options
        $safe_options = [
            'guardify_smart_filter_threshold' => ['type' => 'float', 'min' => 0, 'max' => 100, 'default' => 70],
            'guardify_smart_filter_action'    => ['type' => 'enum', 'values' => ['block', 'otp', 'flag'], 'default' => 'block'],
            'guardify_smart_filter_skip_new'  => ['type' => 'yesno', 'default' => 'yes'],
            'guardify_repeat_blocker_hours'   => ['type' => 'int', 'min' => 1, 'max' => 720, 'default' => 24],
            'guardify_fraud_auto_block_dp'    => ['type' => 'float', 'min' => 0, 'max' => 100, 'default' => 0],
        ];

        foreach ($safe_options as $key => $rule) {
            if (!isset($_POST[$key])) {
                continue;
            }
            $raw = sanitize_text_field(wp_unslash($_POST[$key]));
            switch ($rule['type']) {
                case 'float':
                    $val = max($rule['min'], min($rule['max'], (float) $raw));
                    break;
                case 'int':
                    $val = max($rule['min'], min($rule['max'], absint($raw)));
                    break;
                case 'enum':
                    $val = in_array($raw, $rule['values'], true) ? $raw : $rule['default'];
                    break;
                case 'yesno':
                    $val = $raw === 'yes' ? 'yes' : 'no';
                    break;
                default:
                    $val = $rule['default'];
            }
            update_option($key, $val);
        }

        // Notification statuses (array of status slugs)
        $statuses = isset($_POST['guardify_notification_statuses']) && is_array($_POST['guardify_notification_statuses'])
            ? array_map('sanitize_text_field', wp_unslash($_POST['guardify_notification_statuses']))
            : [];
        update_option('guardify_notification_statuses', $statuses);

        // Notification templates (associative array — validate keys against WC statuses)
        $valid_statuses = array_keys(wc_get_order_statuses());
        $raw_templates = isset($_POST['guardify_notification_templates']) && is_array($_POST['guardify_notification_templates'])
            ? $_POST['guardify_notification_templates'] : [];
        $templates = [];
        foreach ($raw_templates as $slug => $tpl) {
            $slug = sanitize_text_field(wp_unslash($slug));
            if (in_array($slug, $valid_statuses, true)) {
                $templates[$slug] = sanitize_textarea_field(wp_unslash($tpl));
            }
        }
        update_option('guardify_notification_templates', $templates);

        wp_send_json_success(['message' => 'সেটিংস সংরক্ষিত হয়েছে।']);
    }
}

// Initialize
Guardify_Pro::instance();
