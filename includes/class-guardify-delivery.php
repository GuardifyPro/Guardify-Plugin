<?php
defined('ABSPATH') || exit;

/**
 * Guardify Delivery Intelligence — Shows courier summary on WooCommerce order admin.
 */
class Guardify_Delivery {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('wp_ajax_guardify_fetch_delivery', [$this, 'ajax_fetch_delivery']);
    }

    /**
     * Add meta box to WooCommerce order edit page.
     */
    public function add_meta_box() {
        $screen = $this->get_order_screen();
        if ($screen) {
            add_meta_box(
                'guardify-delivery-summary',
                'Guardify — ডেলিভারি ইন্টেলিজেন্স',
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the meta box shell — data loaded via AJAX.
     */
    public function render_meta_box($post_or_order) {
        $order = $this->get_order_from($post_or_order);
        if (!$order) {
            echo '<p>অর্ডার পাওয়া যায়নি।</p>';
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            echo '<p class="gf-text-muted">বিলিং ফোন নম্বর নেই।</p>';
            return;
        }

        $nonce = wp_create_nonce('guardify_delivery_nonce');
        ?>
        <div id="gf-delivery-box" data-phone="<?php echo esc_attr($phone); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="gf-delivery-loading" style="text-align:center; padding:1rem;">
                <span class="spinner is-active" style="float:none;"></span>
                <p style="margin-top:0.5rem; color:#666; font-size:13px;">কুরিয়ার ডেটা লোড হচ্ছে...</p>
            </div>
        </div>
        <style>
            #guardify-delivery-summary .gf-dp-bar { height:6px; border-radius:3px; background:#e5e7eb; margin-top:6px; }
            #guardify-delivery-summary .gf-dp-fill { height:100%; border-radius:3px; transition:width 0.3s; }
            #guardify-delivery-summary .gf-risk-high { color:#dc2626; font-weight:600; }
            #guardify-delivery-summary .gf-risk-medium { color:#d97706; font-weight:600; }
            #guardify-delivery-summary .gf-risk-low { color:#16a34a; font-weight:600; }
            #guardify-delivery-summary .gf-courier-row { display:flex; justify-content:space-between; padding:4px 0; font-size:13px; border-bottom:1px solid #f3f4f6; }
            #guardify-delivery-summary .gf-courier-row:last-child { border-bottom:none; }
            #guardify-delivery-summary .gf-summary-label { font-size:12px; color:#6b7280; }
            #guardify-delivery-summary .gf-summary-value { font-size:18px; font-weight:700; }
        </style>
        <script>
        jQuery(function($){
            var box = $('#gf-delivery-box');
            var phone = box.data('phone');
            var nonce = box.data('nonce');
            if (!phone) return;

            $.post(ajaxurl, {
                action: 'guardify_fetch_delivery',
                _wpnonce: nonce,
                phone: phone
            }, function(res) {
                if (res.success && res.data) {
                    box.html(renderDelivery(res.data));
                } else {
                    box.html('<p style="color:#dc2626; font-size:13px;">ডেটা লোড ব্যর্থ হয়েছে।</p>');
                }
            }).fail(function() {
                box.html('<p style="color:#dc2626; font-size:13px;">সার্ভারে সংযোগ করা যায়নি।</p>');
            });

            function renderDelivery(d) {
                var dpColor = d.dp_ratio >= 80 ? '#16a34a' : (d.dp_ratio >= 50 ? '#d97706' : '#dc2626');
                var riskClass = 'gf-risk-' + (d.risk_level || 'high');
                var riskBn = { low: 'নিম্ন ঝুঁকি', medium: 'মাঝারি ঝুঁকি', high: 'উচ্চ ঝুঁকি' };

                var html = '<div style="text-align:center; padding:4px 0 8px;">';
                html += '<div class="gf-summary-label">ডেলিভারি পারফরম্যান্স</div>';
                html += '<div class="gf-summary-value" style="color:' + dpColor + ';">' + (d.dp_ratio || 0).toFixed(1) + '%</div>';
                html += '<span class="' + riskClass + '" style="font-size:12px;">' + (riskBn[d.risk_level] || 'অজানা') + '</span>';
                html += '<div class="gf-dp-bar"><div class="gf-dp-fill" style="width:' + Math.min(d.dp_ratio || 0, 100) + '%; background:' + dpColor + ';"></div></div>';
                html += '</div>';

                html += '<div style="margin-top:8px; font-size:13px;">';
                html += '<div class="gf-courier-row"><span>মোট পার্সেল</span><strong>' + (d.total_parcels || 0) + '</strong></div>';
                html += '<div class="gf-courier-row"><span>ডেলিভার্ড</span><strong style="color:#16a34a;">' + (d.total_delivered || 0) + '</strong></div>';
                html += '<div class="gf-courier-row"><span>ক্যান্সেল্ড</span><strong style="color:#d97706;">' + (d.total_cancelled || 0) + '</strong></div>';
                html += '<div class="gf-courier-row"><span>রিটার্ন্ড</span><strong style="color:#dc2626;">' + (d.total_returned || 0) + '</strong></div>';
                html += '</div>';

                if (d.providers && d.providers.length > 0) {
                    html += '<div style="margin-top:10px; border-top:1px solid #e5e7eb; padding-top:8px;">';
                    html += '<div class="gf-summary-label" style="margin-bottom:4px;">কুরিয়ার ব্রেকডাউন</div>';
                    d.providers.forEach(function(p) {
                        html += '<div class="gf-courier-row"><span>' + esc(p.provider) + '</span><span>' + p.total_delivered + '/' + p.total_parcels + '</span></div>';
                    });
                    html += '</div>';
                }

                return html;
            }

            function esc(s) { return $('<span>').text(s).html(); }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Fetch delivery summary for a phone number.
     */
    public function ajax_fetch_delivery() {
        check_ajax_referer('guardify_delivery_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        if (empty($phone)) {
            wp_send_json_error('ফোন নম্বর প্রয়োজন।');
        }

        $api = new Guardify_API();
        $result = $api->get('/api/v1/courier/summary', ['phone' => $phone]);

        if (!empty($result['success']) && isset($result['data'])) {
            wp_send_json_success($result['data']);
        } elseif (isset($result['phone'])) {
            // Direct response format from engine
            wp_send_json_success($result);
        }

        $error = isset($result['error']) ? $result['error'] : 'ডেটা লোড ব্যর্থ।';
        wp_send_json_error($error);
    }

    /**
     * Get the WC order screen ID (supports HPOS).
     */
    private function get_order_screen() {
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            $controller = wc_get_container()->get('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController');
            if (method_exists($controller, 'custom_orders_table_usage_is_enabled') && $controller->custom_orders_table_usage_is_enabled()) {
                return wc_get_page_screen_id('shop-order');
            }
        }
        return 'shop_order';
    }

    /**
     * Extract WC_Order from post or order object.
     */
    private function get_order_from($post_or_order) {
        if ($post_or_order instanceof WC_Order) {
            return $post_or_order;
        }
        if (is_a($post_or_order, 'WP_Post')) {
            return wc_get_order($post_or_order->ID);
        }
        return null;
    }
}
