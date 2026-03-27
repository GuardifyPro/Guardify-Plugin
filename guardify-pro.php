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

        // Admin menu
        add_action('admin_menu', [$this, 'register_menu']);

        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // AJAX handlers
        add_action('wp_ajax_guardify_connect', [$this, 'ajax_connect']);
        add_action('wp_ajax_guardify_disconnect', [$this, 'ajax_disconnect']);
        add_action('wp_ajax_guardify_status', [$this, 'ajax_status']);

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
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }
        include GUARDIFY_PATH . 'templates/settings-page.php';
    }

    public function enqueue_admin_assets($hook) {
        $guardify_pages = ['guardify-pro', 'guardify-search'];
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

        wp_localize_script('guardify-admin', 'guardifyData', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('guardify_nonce'),
            'apiKey'   => get_option('guardify_api_key', ''),
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
        $secret_key = isset($_POST['secret_key']) ? sanitize_text_field(wp_unslash($_POST['secret_key'])) : '';

        if (empty($api_key) || empty($secret_key)) {
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
}

// Initialize
Guardify_Pro::instance();
