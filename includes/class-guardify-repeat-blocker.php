<?php
defined('ABSPATH') || exit;

/**
 * Guardify Repeat Order Blocker — Prevents duplicate orders from the same phone
 * within a configurable time window.
 *
 * Uses throw Exception for strong server-side blocking, plus aggressive JS
 * form-submission prevention and popup. Mirrors OrderGuard behaviour.
 */
class Guardify_Repeat_Blocker {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_enabled()) {
            return;
        }

        // Server-side checkout block
        add_action('woocommerce_checkout_process', [$this, 'check_recent_orders']);

        // AJAX real-time phone check
        add_action('wp_ajax_guardify_check_repeat', [$this, 'ajax_check_repeat']);
        add_action('wp_ajax_nopriv_guardify_check_repeat', [$this, 'ajax_check_repeat']);

        // Frontend scripts + aggressive form prevention
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'add_form_submission_prevention']);
    }

    /* ───── helpers ───── */

    public function is_enabled() {
        return get_option('guardify_repeat_blocker_enabled', 'no') === 'yes';
    }

    public function get_time_limit() {
        return absint(get_option('guardify_repeat_blocker_hours', 24));
    }

    public function get_error_message() {
        $time_limit = $this->get_time_limit();
        $default    = sprintf('এই ফোন নম্বর থেকে ইতিমধ্যে অর্ডার করা হয়েছে। অনুগ্রহ করে %d ঘণ্টা পর আবার চেষ্টা করুন।', $time_limit);
        $message    = get_option('guardify_repeat_blocker_message', $default);
        return sprintf($message, $time_limit);
    }

    public function get_support_number() {
        return get_option('guardify_repeat_blocker_support', '');
    }

    /* ───── server-side block ───── */

    public function check_recent_orders() {
        if (current_user_can('manage_woocommerce')) {
            return;
        }

        if (!isset($_POST['billing_phone'])) {
            return;
        }

        $phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));
        $phone = $this->normalize_phone($phone);

        if (empty($phone)) {
            return;
        }

        if ($this->has_recent_order($phone)) {
            // throw Exception — stronger than wc_add_notice, truly blocks checkout
            throw new \Exception($this->get_error_message());
        }
    }

    /* ───── AJAX phone check ───── */

    public function ajax_check_repeat() {
        check_ajax_referer('guardify_repeat_nonce', 'nonce');

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $phone = $this->normalize_phone($phone);

        if (empty($phone) || !$this->is_valid_bd_phone($phone)) {
            wp_send_json_error([
                'blocked'        => false,
                'invalid_format' => true,
                'message'        => 'ভ্যালিড ফোন নম্বর দিন',
            ]);
        }

        $blocked = $this->has_recent_order($phone);

        if ($blocked) {
            wp_send_json_error([
                'blocked'        => true,
                'invalid_format' => false,
                'message'        => $this->get_error_message(),
                'force_block'    => true,
            ]);
        }

        wp_send_json_success([
            'blocked'        => false,
            'invalid_format' => false,
            'message'        => 'OK',
        ]);
    }

    /* ───── order query ───── */

    private function has_recent_order($phone) {
        $phone_variants = [$phone];
        if (strpos($phone, '88') !== 0) {
            $phone_variants[] = '88' . $phone;
        }
        // Also try with +88 prefix
        $phone_variants[] = '+88' . ltrim($phone, '0');

        $time_limit = $this->get_time_limit();
        $current_ts = current_time('timestamp'); // WP timezone

        foreach ($phone_variants as $p) {
            $orders = wc_get_orders([
                'billing_phone' => $p,
                'limit'         => 5,
                'orderby'       => 'date',
                'order'         => 'DESC',
            ]);

            if (empty($orders)) {
                continue;
            }

            foreach ($orders as $order) {
                $order_ts_utc = $order->get_date_created()->getTimestamp();
                $order_ts_wp  = $order_ts_utc + (get_option('gmt_offset') * 3600);
                $hours_passed = ($current_ts - $order_ts_wp) / 3600;

                if ($hours_passed < $time_limit) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ───── frontend scripts ───── */

    public function enqueue_scripts() {
        if (!is_checkout()) {
            return;
        }

        wp_enqueue_style('dashicons');

        wp_enqueue_script(
            'guardify-repeat-blocker',
            GUARDIFY_URL . 'assets/js/repeat-blocker.js',
            ['jquery'],
            GUARDIFY_VERSION,
            true
        );

        wp_localize_script('guardify-repeat-blocker', 'guardifyRepeat', [
            'ajaxUrl'              => admin_url('admin-ajax.php'),
            'nonce'                => wp_create_nonce('guardify_repeat_nonce'),
            'timeLimit'            => $this->get_time_limit(),
            'errorMessage'         => $this->get_error_message(),
            'showPopup'            => true,
            'supportNumber'        => $this->get_support_number(),
            'disablePlaceOrder'    => true,
            'placeOrderButtonText' => __('Place order', 'woocommerce'),
            'validatingText'       => 'ফোন যাচাই হচ্ছে...',
            'invalidText'          => 'ফোন নম্বর অবৈধ',
            'forceBlock'           => true,
        ]);
    }

    /**
     * Aggressive JS form-submission prevention injected in wp_footer.
     * Overrides jQuery .submit() and .trigger() to ensure blocked phone
     * can never submit — even if other plugins try to force it.
     */
    public function add_form_submission_prevention() {
        if (!is_checkout() || !$this->is_enabled()) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            window.guardifyPhoneBlocked = false;
            window.guardifyRepeatOrderBlocked = false;

            function gfPreventSubmission(reason) {
                window.guardifyPhoneBlocked = true;
                window.guardifyRepeatOrderBlocked = true;
                if (typeof showGuardifyRepeatPopup === 'function') {
                    showGuardifyRepeatPopup(reason);
                }
                if ($('#billing_phone').length) {
                    $('html, body').animate({ scrollTop: $('#billing_phone').offset().top - 100 }, 500);
                }
                return false;
            }

            // Intercept checkout form submit
            $('form.checkout').off('submit.guardifyRepeat').on('submit.guardifyRepeat', function(e) {
                if (window.guardifyPhoneBlocked === true) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return gfPreventSubmission('Phone blocked by repeat blocker');
                }
            });

            // Intercept WC checkout_place_order event
            $(document.body).off('checkout_place_order.guardifyRepeat').on('checkout_place_order.guardifyRepeat', function() {
                if (window.guardifyPhoneBlocked === true) return false;
                return true;
            });

            // Intercept place order button click
            $(document).off('click.guardifyRepeat', '#place_order').on('click.guardifyRepeat', '#place_order', function(e) {
                if (window.guardifyPhoneBlocked === true) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    return gfPreventSubmission('Phone blocked by repeat blocker');
                }
            });

            // Override jQuery .submit() and .trigger('submit')
            var origSubmit = $.fn.submit;
            $.fn.submit = function() {
                if (window.guardifyPhoneBlocked === true) return this;
                return origSubmit.apply(this, arguments);
            };
            var origTrigger = $.fn.trigger;
            $.fn.trigger = function(event) {
                if (event === 'submit' && window.guardifyPhoneBlocked === true) return this;
                return origTrigger.apply(this, arguments);
            };
        });
        </script>
        <?php
    }

    /* ───── utilities ───── */

    private function normalize_phone($phone) {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);
        return $phone;
    }

    private function is_valid_bd_phone($phone) {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }
}
