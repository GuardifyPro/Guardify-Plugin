<?php
defined('ABSPATH') || exit;

/**
 * Guardify Custom Order Status — Registers wc-otp-pending for orders
 * that require OTP verification before processing.
 */
class Guardify_Custom_Status {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_status']);
        add_filter('wc_order_statuses', [$this, 'add_to_statuses']);
        add_filter('woocommerce_reports_order_statuses', [$this, 'add_to_reports']);
        add_action('admin_head', [$this, 'admin_styles']);
    }

    /**
     * Register the custom post status.
     */
    public function register_status() {
        register_post_status('wc-otp-pending', [
            'label'                     => 'OTP পেন্ডিং',
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'OTP পেন্ডিং <span class="count">(%s)</span>',
                'OTP পেন্ডিং <span class="count">(%s)</span>',
                'guardify-pro'
            ),
        ]);
    }

    /**
     * Add custom status to WC order status dropdown.
     */
    public function add_to_statuses($statuses) {
        $new = [];
        foreach ($statuses as $key => $label) {
            $new[$key] = $label;
            if ($key === 'wc-pending') {
                $new['wc-otp-pending'] = 'OTP পেন্ডিং';
            }
        }
        return $new;
    }

    /**
     * Include in WC reports.
     */
    public function add_to_reports($statuses) {
        $statuses[] = 'otp-pending';
        return $statuses;
    }

    /**
     * Admin styles for the status badge.
     */
    public function admin_styles() {
        echo '<style>
            .order-status.status-otp-pending,
            .woocommerce-order-status.status-otp-pending {
                background: #f59e0b; color: #fff; border-radius: 4px;
                padding: 2px 8px; font-size: 11px; font-weight: 600;
                text-transform: uppercase; letter-spacing: 0.3px;
            }
            .widefat .column-order_status mark.otp-pending { background: #f59e0b; color: #fff; }
            .widefat .column-order_status mark.otp-pending::after {
                content: "OTP পেন্ডিং"; font-size: 11px; font-weight: 500;
            }
        </style>';
    }

    /**
     * Check if order has OTP pending status.
     */
    public static function is_otp_pending($order_id) {
        $order = wc_get_order($order_id);
        return $order && $order->get_status() === 'otp-pending';
    }
}
