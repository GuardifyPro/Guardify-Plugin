<?php
defined('ABSPATH') || exit;

/**
 * Guardify OTP Verification — Phone verification via Engine-generated OTP.
 *
 * Flow: Plugin sends phone to Engine → Engine generates OTP, bcrypt-hashes,
 * stores in DB, sends via MiMSMS → Customer enters OTP → Plugin sends to
 * Engine for server-side verification.
 */
class Guardify_OTP {

    private static $instance = null;
    private $session_key = 'guardify_otp_verified';
    private $session_phone_key = 'guardify_otp_verified_phone';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_otp_enabled', 'no') !== 'yes') {
            return;
        }

        // Checkout hooks
        add_action('woocommerce_checkout_process', [$this, 'check_verification'], 15);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_verification_status']);

        // Frontend
        add_action('wp_footer', [$this, 'render_otp_modal']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // AJAX
        add_action('wp_ajax_guardify_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_guardify_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_guardify_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_guardify_verify_otp', [$this, 'ajax_verify_otp']);
    }

    /**
     * Block checkout if phone not verified.
     */
    public function check_verification() {
        if (!WC()->session) {
            $api = new Guardify_API();
            if ($api->is_connected()) {
                wc_add_notice('ফোন ভেরিফিকেশন সম্ভব হয়নি। অনুগ্রহ করে ব্রাউজার চেকআউট ব্যবহার করুন।', 'error');
            }
            return;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return; // Fail open
        }

        $verified = WC()->session->get($this->session_key, false);
        if (!$verified) {
            wc_add_notice('অর্ডার নিশ্চিত করতে আপনার ফোন নম্বর ভেরিফাই করুন।', 'error');
            return;
        }

        // Verify the OTP was for the current billing phone
        $verified_phone = WC()->session->get($this->session_phone_key, '');
        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $billing_phone = preg_replace('/[\s\-]/', '', $billing_phone);
        $billing_phone = preg_replace('/^\+?88/', '', $billing_phone);

        if ($verified_phone !== $billing_phone) {
            WC()->session->set($this->session_key, null);
            WC()->session->set($this->session_phone_key, null);
            wc_add_notice('ফোন নম্বর পরিবর্তন হয়েছে। আবার OTP ভেরিফাই করুন।', 'error');
        }
    }

    /**
     * Save verification status to order meta.
     */
    public function save_verification_status($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $verified = WC()->session ? WC()->session->get($this->session_key, false) : false;
        $order->update_meta_data('_guardify_phone_verified', $verified ? 'yes' : 'no');
        $order->save();

        // Clear session
        if (WC()->session) {
            WC()->session->set($this->session_key, null);
            WC()->session->set($this->session_phone_key, null);
        }
    }

    /**
     * AJAX: Send OTP via Engine.
     */
    public function ajax_send_otp() {
        check_ajax_referer('guardify_otp_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error(['message' => 'সঠিক ফোন নম্বর দিন (01XXXXXXXXX)']);
        }

        // Rate limit: max 3 OTP requests per phone per 5 minutes
        $throttle_key = 'gf_otp_' . md5($phone);
        $attempts = (int) get_transient($throttle_key);
        if ($attempts >= 3) {
            wp_send_json_error(['message' => 'অনেক বেশি চেষ্টা। ৫ মিনিট পর আবার চেষ্টা করুন।']);
        }
        set_transient($throttle_key, $attempts + 1, 5 * MINUTE_IN_SECONDS);

        $api = new Guardify_API();
        $result = $api->post('/api/v1/otp/send', [
            'phone'   => $phone,
            'purpose' => 'checkout',
        ]);

        if (!empty($result['success']) && $result['success'] === true) {
            wp_send_json_success(['message' => 'OTP পাঠানো হয়েছে।']);
        }

        $msg = isset($result['error']) ? $result['error'] : 'OTP পাঠানো যায়নি।';
        wp_send_json_error(['message' => $msg]);
    }

    /**
     * AJAX: Verify OTP via Engine.
     */
    public function ajax_verify_otp() {
        check_ajax_referer('guardify_otp_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);
        $otp   = isset($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';

        if (empty($phone) || empty($otp)) {
            wp_send_json_error(['message' => 'ফোন নম্বর ও OTP প্রয়োজন।']);
        }

        $api = new Guardify_API();
        $result = $api->post('/api/v1/otp/verify', [
            'phone'   => $phone,
            'otp'     => $otp,
            'purpose' => 'checkout',
        ]);

        if (!empty($result['success']) && $result['success'] === true) {
            // Mark verified in WC session with the specific phone
            if (WC()->session) {
                WC()->session->set($this->session_key, true);
                WC()->session->set($this->session_phone_key, $phone);
            }
            wp_send_json_success(['message' => 'ফোন নম্বর ভেরিফাই হয়েছে!']);
        }

        $msg = isset($result['error']) ? $result['error'] : 'ভুল OTP।';
        wp_send_json_error(['message' => $msg]);
    }

    /**
     * Enqueue OTP scripts on checkout page.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'guardify-otp',
            GUARDIFY_URL . 'assets/css/otp.css',
            [],
            GUARDIFY_VERSION
        );

        wp_enqueue_script(
            'guardify-otp',
            GUARDIFY_URL . 'assets/js/otp.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-otp', 'guardifyOTP', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('guardify_otp_nonce'),
        ]);
    }

    /**
     * Render OTP modal on checkout page.
     */
    public function render_otp_modal() {
        if (!is_checkout()) {
            return;
        }
        ?>
        <div id="guardify-otp-modal" class="gf-otp-modal" style="display:none;">
            <div class="gf-otp-modal-overlay"></div>
            <div class="gf-otp-modal-content">
                <div class="gf-otp-modal-header">
                    <h3>ফোন ভেরিফিকেশন</h3>
                </div>
                <div class="gf-otp-modal-body">
                    <p class="gf-otp-info">আপনার ফোনে একটি OTP কোড পাঠানো হয়েছে। অর্ডার নিশ্চিত করতে কোডটি নিচে লিখুন।</p>
                    <div class="gf-otp-message"></div>
                    <div class="gf-otp-input-wrap">
                        <input type="text" id="gf-otp-input" class="gf-otp-input" placeholder="OTP কোড" maxlength="6" inputmode="numeric" pattern="[0-9]*" autocomplete="one-time-code">
                    </div>
                    <button type="button" id="gf-otp-verify-btn" class="gf-otp-btn gf-otp-btn-primary">ভেরিফাই করুন</button>
                    <div class="gf-otp-footer">
                        <span>OTP পাননি?</span>
                        <span class="gf-otp-countdown" style="display:none;"></span>
                        <a href="#" id="gf-otp-resend">আবার পাঠান</a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
