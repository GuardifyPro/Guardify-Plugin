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
                'Guardify — Delivery Intelligence',
                [$this, 'render_meta_box'],
                $screen,
                'normal',
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
            echo '<p>Order not found.</p>';
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            echo '<p class="gf-text-muted">No billing phone number.</p>';
            return;
        }

        $nonce = wp_create_nonce('guardify_delivery_nonce');
        ?>
        <div id="gf-delivery-box" data-phone="<?php echo esc_attr($phone); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="gf-delivery-loading" style="text-align:center; padding:20px;">
                <span class="spinner is-active" style="float:none;"></span>
                <p style="margin-top:0.5rem; color:#4a5568; font-size:13px;">Loading courier data...</p>
            </div>
        </div>
        <style>
            #guardify-delivery-summary .inside { padding: 0; }
            .gf-delivery-wrapper {
                padding: 15px;
                background: #fff;
            }
            .gf-delivery-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 15px;
                background: #fff;
            }
            .gf-delivery-table th,
            .gf-delivery-table td {
                padding: 10px 12px;
                text-align: center;
                border: 1px solid #e2e8f0;
                font-size: 13px;
            }
            .gf-delivery-table th {
                background: #f8fafc;
                font-weight: 600;
                color: #1e293b;
            }
            .gf-delivery-table td.gf-courier-name {
                text-align: left;
                font-weight: 500;
                text-transform: capitalize;
            }
            .gf-delivery-table td.gf-delivered {
                background-color: #f0fdf4;
                color: #166534;
                font-weight: 600;
            }
            .gf-delivery-table td.gf-returned {
                background-color: #fef2f2;
                color: #991b1b;
                font-weight: 600;
            }
            .gf-delivery-table tr.gf-totals-row {
                background: #f8fafc;
                font-weight: 700;
            }
            .gf-progress-bar {
                height: 22px;
                background: rgba(91, 10, 250, 0.1);
                border-radius: 11px;
                overflow: hidden;
                margin: 15px 0;
                position: relative;
            }
            .gf-progress-fill {
                height: 100%;
                background: linear-gradient(90deg, #7c3aed, #5b0afa);
                border-radius: 11px;
                transition: width 0.5s ease;
                position: relative;
            }
            .gf-progress-text {
                position: absolute;
                width: 100%;
                text-align: center;
                color: #fff;
                font-size: 12px;
                font-weight: 600;
                line-height: 22px;
                top: 0;
                left: 0;
                text-shadow: 0 1px 2px rgba(0,0,0,0.3);
            }
            .gf-fraud-report {
                margin-top: 15px;
                padding: 15px;
                background: #f6efff;
                border-radius: 8px;
                border: 1px solid #e9d5ff;
            }
            .gf-fraud-report h4 {
                margin: 0 0 10px;
                color: #5b21b6;
                font-size: 14px;
                font-weight: 700;
            }
            .gf-fraud-report ul {
                margin: 0;
                padding-left: 18px;
                color: #4a5568;
                line-height: 1.6;
                list-style-type: disc;
            }
            .gf-fraud-report li { margin-bottom: 4px; }
            .gf-fraud-report li:last-child { margin-bottom: 0; }
            .gf-no-data {
                text-align: center;
                padding: 20px;
                color: #6b7280;
                font-size: 13px;
            }
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
                    box.html('<div class="gf-no-data">Failed to load delivery data.</div>');
                }
            }).fail(function() {
                box.html('<div class="gf-no-data">Could not connect to server.</div>');
            });

            function renderDelivery(d) {
                var providers = d.providers || [];
                var totalParcels = d.total_parcels || 0;
                var totalDelivered = d.total_delivered || 0;
                var totalCancelled = d.total_cancelled || 0;
                var totalReturned = d.total_returned || 0;
                var totalFailed = totalCancelled + totalReturned;
                var dpRatio = parseFloat(d.dp_ratio) || 0;

                if (providers.length === 0 && totalParcels === 0) {
                    return '<div class="gf-no-data">No delivery history found for this phone number.</div>';
                }

                var html = '<div class="gf-delivery-wrapper">';

                // Courier breakdown table
                html += '<table class="gf-delivery-table">';
                html += '<thead><tr><th style="text-align:left;">Courier</th><th>Total</th><th>Delivered</th><th>Returned</th><th>Success Ratio</th></tr></thead>';
                html += '<tbody>';

                var fraudDetails = [];

                for (var i = 0; i < providers.length; i++) {
                    var p = providers[i];
                    var pTotal = p.total_parcels || 0;
                    var pDelivered = p.total_delivered || 0;
                    var pReturned = (p.total_cancelled || 0) + (p.total_returned || 0);
                    var pRatio = pTotal > 0 ? ((pDelivered / pTotal) * 100).toFixed(2) : '0.00';

                    html += '<tr>';
                    html += '<td class="gf-courier-name">' + esc(p.provider) + '</td>';
                    html += '<td>' + pTotal + '</td>';
                    html += '<td class="gf-delivered">' + pDelivered + '</td>';
                    html += '<td class="gf-returned">' + pReturned + '</td>';
                    html += '<td>' + pRatio + '%</td>';
                    html += '</tr>';

                    // Collect fraud details from Steadfast
                    if (p.fraud_details && p.fraud_details.length > 0) {
                        fraudDetails = fraudDetails.concat(p.fraud_details);
                    }
                }

                // Totals row
                html += '<tr class="gf-totals-row">';
                html += '<td class="gf-courier-name"><strong>Total</strong></td>';
                html += '<td><strong>' + totalParcels + '</strong></td>';
                html += '<td class="gf-delivered"><strong>' + totalDelivered + '</strong></td>';
                html += '<td class="gf-returned"><strong>' + totalFailed + '</strong></td>';
                html += '<td><strong>' + dpRatio.toFixed(2) + '%</strong></td>';
                html += '</tr>';

                html += '</tbody></table>';

                // Progress bar
                var barWidth = Math.min(dpRatio, 100);
                html += '<div class="gf-progress-bar">';
                html += '<div class="gf-progress-fill" style="width:' + barWidth + '%;"></div>';
                html += '<div class="gf-progress-text">' + dpRatio.toFixed(2) + '%</div>';
                html += '</div>';

                // Steadfast Fraud Report (if available)
                if (fraudDetails.length > 0) {
                    html += '<div class="gf-fraud-report">';
                    html += '<h4>Steadfast Fraud Report</h4>';
                    html += '<ul>';
                    for (var j = 0; j < fraudDetails.length; j++) {
                        var detail = fraudDetails[j];
                        if (detail && detail !== 'null' && detail !== '[]') {
                            html += '<li>' + esc(detail) + '</li>';
                        }
                    }
                    html += '</ul></div>';
                }

                html += '</div>';
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
            wp_send_json_error('Phone number required.');
        }

        $api = new Guardify_API();
        $result = $api->get('/api/v1/courier/summary', ['phone' => $phone]);

        if (!empty($result['success']) && isset($result['data'])) {
            wp_send_json_success($result['data']);
        } elseif (isset($result['phone'])) {
            // Direct response format from engine
            wp_send_json_success($result);
        }

        $error = isset($result['error']) ? $result['error'] : 'Failed to load data.';
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
