<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone Validation — Validates Bangladesh phone numbers at checkout.
 */
class Guardify_Phone_Validation {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_phone']);
        add_filter('woocommerce_checkout_fields', [$this, 'set_phone_attributes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Enqueue phone validation scripts and styles on checkout.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'guardify-phone-validation',
            GUARDIFY_URL . 'assets/css/phone-validation.css',
            [],
            GUARDIFY_VERSION
        );

        // Add animated error notice styles for WC checkout notices
        wp_add_inline_style('guardify-phone-validation', $this->get_checkout_error_styles());

        wp_enqueue_script(
            'guardify-phone-validation',
            GUARDIFY_URL . 'assets/js/phone-validation.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-phone-validation', 'guardifyPhoneVal', [
            'enabled' => get_option('guardify_phone_validation_enabled', '1'),
            'message' => 'সঠিক বাংলাদেশী মোবাইল নম্বর দিন (01XXXXXXXXX)',
        ]);
    }

    /**
     * Animated checkout error notice styles — gradient background, shake, pulse icon.
     */
    private function get_checkout_error_styles() {
        return "
            .woocommerce-error.gf-phone-error {
                position: relative;
                padding: 15px 20px 15px 45px;
                margin: 0 0 20px;
                border-radius: 8px;
                background: linear-gradient(135deg, #ff6b6b 0%, #ff4444 100%);
                color: #ffffff;
                border: none;
                box-shadow: 0 4px 15px rgba(255, 68, 68, 0.2);
                animation: gf-slideInDown 0.5s ease-out, gf-errorShake 0.8s cubic-bezier(.36,.07,.19,.97) both;
            }
            .woocommerce-error.gf-phone-error::before {
                content: '\\f534';
                font-family: dashicons;
                position: absolute;
                top: 50%;
                left: 15px;
                transform: translateY(-50%);
                font-size: 20px;
                color: #ffffff;
                animation: gf-pulse 2s infinite;
            }
            .woocommerce-error.gf-phone-error li {
                color: #ffffff;
                font-weight: 500;
            }
            @keyframes gf-slideInDown {
                from { transform: translateY(-20px); opacity: 0; }
                to   { transform: translateY(0); opacity: 1; }
            }
            @keyframes gf-errorShake {
                10%, 90% { transform: translateX(-1px); }
                20%, 80% { transform: translateX(2px); }
                30%, 50%, 70% { transform: translateX(-4px); }
                40%, 60% { transform: translateX(4px); }
            }
            @keyframes gf-pulse {
                0%   { transform: translateY(-50%) scale(1); }
                50%  { transform: translateY(-50%) scale(1.2); }
                100% { transform: translateY(-50%) scale(1); }
            }
        ";
    }

    /**
     * Set phone field attributes for frontend validation hints.
     */
    public function set_phone_attributes($fields) {
        if (isset($fields['billing']['billing_phone'])) {
            $fields['billing']['billing_phone']['maxlength'] = 11;
            $fields['billing']['billing_phone']['custom_attributes'] = [
                'pattern'     => '01[3-9][0-9]{8}',
                'inputmode'   => 'tel',
                'placeholder' => '01XXXXXXXXX',
            ];
        }
        return $fields;
    }

    /**
     * Server-side phone validation on checkout.
     */
    public function validate_checkout_phone() {
        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

        // Strip any spaces, dashes, or +88 prefix
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone)) {
            wc_add_notice('ফোন নম্বর দিন।', 'error');
            return;
        }

        if (!$this->is_valid_bd_phone($phone)) {
            wc_add_notice('সঠিক বাংলাদেশী মোবাইল নম্বর দিন (01XXXXXXXXX)।', 'error', ['class' => 'gf-phone-error']);
        }
    }

    /**
     * Check if phone validation is enabled.
     */
    public function is_validation_enabled() {
        return get_option('guardify_phone_validation_enabled', '1') === '1';
    }

    /**
     * Validate a Bangladesh mobile number.
     * Pattern: 01[3-9] followed by 8 digits = 11 digits total.
     */
    public function is_valid_bd_phone($phone) {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }
}
