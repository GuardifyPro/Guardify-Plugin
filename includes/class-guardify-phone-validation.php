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

        wp_enqueue_script(
            'guardify-phone-validation',
            GUARDIFY_URL . 'assets/js/phone-validation.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-phone-validation', 'guardifyPhoneVal', [
            'message' => 'সঠিক বাংলাদেশী মোবাইল নম্বর দিন (01XXXXXXXXX)',
        ]);
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
            wc_add_notice('সঠিক বাংলাদেশী মোবাইল নম্বর দিন (01XXXXXXXXX)।', 'error');
        }
    }

    /**
     * Validate a Bangladesh mobile number.
     * Pattern: 01[3-9] followed by 8 digits = 11 digits total.
     */
    public function is_valid_bd_phone($phone) {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }
}
