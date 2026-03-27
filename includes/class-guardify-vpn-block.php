<?php
defined('ABSPATH') || exit;

/**
 * Guardify VPN/Proxy Block — Detects VPN/proxy usage via Engine IP check.
 *
 * Sends the customer's IP to the Guardify Engine, which queries ip-api.com.
 * If proxy/VPN/Tor is detected, shows a warning popup and blocks checkout.
 */
class Guardify_VPN_Block {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_vpn_block_enabled', 'no') !== 'yes') {
            return;
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_popup']);
        add_action('wp_ajax_guardify_check_vpn', [$this, 'ajax_check_vpn']);
        add_action('wp_ajax_nopriv_guardify_check_vpn', [$this, 'ajax_check_vpn']);

        // Server-side enforcement at checkout
        add_action('woocommerce_checkout_process', [$this, 'block_vpn_at_checkout'], 5);
    }

    /**
     * Server-side VPN/proxy block during checkout validation.
     */
    public function block_vpn_at_checkout() {
        $ip = $this->get_client_ip();
        if (empty($ip)) {
            return;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return; // Fail open
        }

        $result = $api->post('/api/v1/ip/check', ['ip' => $ip]);
        $d = isset($result['data']) ? $result['data'] : $result;

        $risk = isset($d['risk_level']) ? $d['risk_level'] : 'clean';
        $is_vpn = !empty($d['is_vpn']);
        $is_proxy = !empty($d['is_proxy']);

        if ($risk === 'high_risk' || $risk === 'high' || $is_vpn || $is_proxy) {
            wc_add_notice('VPN/প্রক্সি সনাক্ত হয়েছে। নিরাপত্তার কারণে অর্ডার প্লেস করা যাচ্ছে না। অনুগ্রহ করে VPN বন্ধ করে আবার চেষ্টা করুন।', 'error');
        }
    }

    /**
     * AJAX: Check visitor IP via Engine.
     */
    public function ajax_check_vpn() {
        check_ajax_referer('guardify_vpn_nonce', 'nonce');

        $ip = $this->get_client_ip();
        if (empty($ip)) {
            wp_send_json_success(['risk_level' => 'clean']);
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_success(['risk_level' => 'clean']); // Fail open
        }

        $result = $api->post('/api/v1/ip/check', ['ip' => $ip]);

        // Normalize wrapped API response
        $d = isset($result['data']) ? $result['data'] : $result;

        if (isset($d['risk_level'])) {
            wp_send_json_success([
                'risk_level' => $d['risk_level'],
                'is_proxy'   => !empty($d['is_proxy']),
                'is_vpn'     => !empty($d['is_vpn']),
                'country'    => isset($d['country']) ? $d['country'] : '',
            ]);
        }

        wp_send_json_success(['risk_level' => 'clean']);
    }

    /**
     * Get the real client IP with proxy header support.
     */
    private function get_client_ip() {
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_REAL_IP',         // Nginx
            'HTTP_X_FORWARDED_FOR',   // Proxy
            'REMOTE_ADDR',            // Direct
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field(wp_unslash($_SERVER[$header]));
                // X-Forwarded-For can have comma-separated IPs; take the first
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    }

    /**
     * Enqueue VPN check scripts on checkout and cart pages.
     */
    public function enqueue_scripts() {
        if (!is_checkout() && !is_cart()) {
            return;
        }

        wp_enqueue_style(
            'guardify-vpn',
            GUARDIFY_URL . 'assets/css/vpn-block.css',
            [],
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-vpn',
            GUARDIFY_URL . 'assets/js/vpn-block.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-vpn', 'guardifyVPN', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_vpn_nonce'),
        ]);
    }

    /**
     * Render VPN warning popup template.
     */
    public function render_popup() {
        if (!is_checkout() && !is_cart()) {
            return;
        }
        ?>
        <div id="guardify-vpn-popup" class="gf-vpn-popup" style="display:none;">
            <div class="gf-vpn-popup-overlay"></div>
            <div class="gf-vpn-popup-content">
                <div class="gf-vpn-popup-icon">⚠️</div>
                <h3>VPN/Proxy সনাক্ত হয়েছে</h3>
                <p>আপনি VPN বা প্রক্সি ব্যবহার করছেন বলে মনে হচ্ছে। নিরাপত্তার কারণে VPN ব্যবহার করে অর্ডার দেওয়া সম্ভব নয়।</p>
                <p class="gf-vpn-warning">দয়া করে আপনার VPN বন্ধ করে আবার চেষ্টা করুন।</p>
            </div>
        </div>
        <?php
    }
}
