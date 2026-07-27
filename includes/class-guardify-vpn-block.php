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

        // Cache VPN check result per IP for 10 min. The tight budget is because this runs
        // inside woocommerce_checkout_process, before the order exists: a request that
        // outlives max_execution_time loses the sale with no record it was attempted.
        $cache_key = 'gf_vpn_' . md5($ip);
        $result = get_transient($cache_key);
        if (false === $result) {
            $result = $api->post('/api/v1/ip/check', ['ip' => $ip], Guardify_API::checkout_opts());
            if (is_array($result) && empty($result['error'])) {
                set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
            }
        }
        $d = isset($result['data']) ? $result['data'] : $result;

        $risk     = isset($d['risk_level']) ? $d['risk_level'] : 'clean';
        $is_vpn   = !empty($d['is_vpn']);
        $is_proxy = !empty($d['is_proxy']);

        if (!($risk === 'high_risk' || $risk === 'high' || $is_vpn || $is_proxy)) {
            return;
        }

        // Bangladesh is a mobile-first market and the mobile operators run large carrier-grade
        // NAT ranges, a meaningful share of which public IP-reputation feeds classify as
        // proxies. Blocking on that signal alone refuses ordinary customers on their phones —
        // the exact people the merchant most wants to sell to — and the merchant has no way to
        // see it happening, because the order is never created.
        //
        // So enforcement is opt-in. By default the detection is recorded on the order for the
        // merchant to review, and a merchant who has decided they want hard blocking can turn
        // it on knowingly.
        if (get_option('guardify_vpn_block_enforce', 'no') !== 'yes') {
            if (WC()->session) {
                WC()->session->set('guardify_vpn_flagged', ['ip' => $ip, 'risk_level' => $risk]);
            }
            if (!has_action('woocommerce_checkout_order_processed', [$this, 'save_vpn_flag_to_order'])) {
                add_action('woocommerce_checkout_order_processed', [$this, 'save_vpn_flag_to_order'], 10, 1);
            }
            return;
        }

        wc_add_notice(
            esc_html__('VPN/প্রক্সি সনাক্ত হয়েছে। নিরাপত্তার কারণে অর্ডার প্লেস করা যাচ্ছে না। অনুগ্রহ করে VPN বন্ধ করে আবার চেষ্টা করুন।', 'guardify-pro'),
            'error'
        );
    }

    /**
     * Record a proxy detection on the order instead of refusing it.
     *
     * This is what makes the advisory default useful rather than merely harmless: the
     * merchant sees which orders were flagged and can judge for themselves whether the
     * signal is worth acting on for their customer base.
     */
    public function save_vpn_flag_to_order($order_id) {
        if (!WC()->session) {
            return;
        }
        $flag = WC()->session->get('guardify_vpn_flagged');
        if (!$flag) {
            return;
        }
        WC()->session->set('guardify_vpn_flagged', null);

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $order->update_meta_data('_guardify_vpn_flagged', 'yes');
        $order->add_order_note(
            esc_html__('Guardify: এই অর্ডারটি VPN/প্রক্সি IP থেকে এসেছে বলে সনাক্ত হয়েছে। (মোবাইল অপারেটরের NAT-ও কখনো এভাবে ধরা পড়ে — যাচাই করে নিন।)', 'guardify-pro')
        );
        $order->save();
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

        // Cache VPN check result per IP for 10 min
        $cache_key = 'gf_vpn_' . md5($ip);
        $result = get_transient($cache_key);
        if (false === $result) {
            $result = $api->post('/api/v1/ip/check', ['ip' => $ip]);
            set_transient($cache_key, $result, 10 * MINUTE_IN_SECONDS);
        }

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
     * The visitor's address, from the one helper that decides what may be believed.
     *
     * This used to walk the proxy headers and trust the first one that parsed. For a feature
     * that refuses checkouts, that meant anyone could pick which address they were judged on
     * — including picking a clean one to look like an ordinary customer, which is exactly
     * what VPN detection exists to notice.
     */
    private function get_client_ip() {
        return Guardify_Client_IP::get();
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
