<?php
defined('ABSPATH') || exit;

/**
 * Guardify Quick View — Injects delivery intelligence, fraud flags,
 * and courier status into the WooCommerce order preview modal (eye icon).
 *
 * Uses WC hooks:
 *   - woocommerce_admin_order_preview_get_order_details (add data to AJAX response)
 *   - woocommerce_admin_order_preview_end (render HTML template in modal)
 */
class Guardify_Quick_View {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Add custom data to the order preview AJAX
        add_filter('woocommerce_admin_order_preview_get_order_details', [$this, 'add_preview_data'], 10, 2);

        // Render HTML at the bottom of the preview modal
        add_action('woocommerce_admin_order_preview_end', [$this, 'render_preview_section']);

        // Admin styles and scripts for the preview
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add Guardify data to the order preview AJAX response.
     */
    public function add_preview_data($data, $order) {
        $phone = $order->get_billing_phone();
        $phone_clean = preg_replace('/[\s\-]/', '', $phone);
        $phone_clean = preg_replace('/^\+?88/', '', $phone_clean);

        $gf = [];

        // --- Phone & Customer Info ---
        $gf['phone']        = $phone_clean;
        $gf['phone_valid']  = (bool) preg_match('/^01[3-9]\d{8}$/', $phone_clean);

        // --- Order History (from this WooCommerce site) ---
        if ($gf['phone_valid']) {
            $prev_orders = wc_get_orders([
                'billing_phone' => $phone,
                'limit'         => -1,
                'return'        => 'ids',
                'exclude'       => [$order->get_id()],
            ]);
            $gf['local_order_count'] = count($prev_orders);

            // Count completed vs cancelled on this site
            $completed = 0;
            $cancelled = 0;
            // Only check last 50 for performance
            $check_ids = array_slice($prev_orders, 0, 50);
            foreach ($check_ids as $oid) {
                $o = wc_get_order($oid);
                if (!$o) continue;
                $s = $o->get_status();
                if ($s === 'completed') $completed++;
                if (in_array($s, ['cancelled', 'refunded', 'failed'])) $cancelled++;
            }
            $gf['local_completed'] = $completed;
            $gf['local_cancelled'] = $cancelled;
        } else {
            $gf['local_order_count'] = 0;
            $gf['local_completed']   = 0;
            $gf['local_cancelled']   = 0;
        }

        // --- Fraud Flags ---
        $gf['dp_flagged'] = $order->get_meta('_guardify_dp_flagged') === 'yes';
        $gf['dp_ratio']   = $order->get_meta('_guardify_dp_ratio');
        $gf['device_id']  = $order->get_meta('_guardify_device_id');
        $gf['ip_address'] = $order->get_meta('_guardify_ip_address');

        // Check if phone is blocked
        $gf['is_blocked'] = false;
        $gf['block_reason'] = '';
        if ($gf['phone_valid']) {
            global $wpdb;
            $table = $wpdb->prefix . 'guardify_fraud_tracking';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT is_blocked, block_reason FROM $table WHERE phone = %s",
                    $phone_clean
                ));
                if ($row && $row->is_blocked) {
                    $gf['is_blocked']    = true;
                    $gf['block_reason']  = $row->block_reason ?: '';
                }
            }
        }

        // --- Courier Status ---
        $gf['courier_provider']    = $order->get_meta('_guardify_courier_provider');
        $gf['courier_consignment'] = $order->get_meta('_guardify_consignment_id');
        $gf['courier_status']      = $order->get_meta('_guardify_courier_status');

        $data['guardify'] = $gf;

        return $data;
    }

    /**
     * Render the Guardify section in the modal using Mustache-style templates.
     * WC preview uses Backbone/Underscore templates with {{ }} syntax.
     */
    public function render_preview_section() {
        ?>
        <# if (data.guardify) { #>
        <div class="gf-preview-section">
            <div class="gf-preview-header">
                <strong>Guardify Intelligence</strong>
            </div>

            <div class="gf-preview-grid">
                {{! --- Order History --- }}
                <div class="gf-preview-card">
                    <div class="gf-preview-card-title">Order History</div>
                    <div class="gf-preview-card-body">
                        <span class="gf-preview-stat">{{ data.guardify.local_order_count }}</span>
                        <span class="gf-preview-label">previous orders</span>
                    </div>
                    <# if (data.guardify.local_order_count > 0) { #>
                    <div class="gf-preview-meta">
                        <span style="color:#16a34a;">&#10003; {{ data.guardify.local_completed }} completed</span>
                        <# if (data.guardify.local_cancelled > 0) { #>
                        <span style="color:#dc2626;">&#10007; {{ data.guardify.local_cancelled }} cancelled</span>
                        <# } #>
                    </div>
                    <# } #>
                </div>

                {{! --- Fraud Status --- }}
                <div class="gf-preview-card">
                    <div class="gf-preview-card-title">Fraud Status</div>
                    <div class="gf-preview-card-body">
                        <# if (data.guardify.is_blocked) { #>
                            <span class="gf-badge gf-badge-danger">BLOCKED</span>
                            <# if (data.guardify.block_reason) { #>
                            <span class="gf-preview-meta" style="margin-top:4px;">{{ data.guardify.block_reason }}</span>
                            <# } #>
                        <# } else if (data.guardify.dp_flagged) { #>
                            <span class="gf-badge gf-badge-warning">DP FLAGGED</span>
                            <# if (data.guardify.dp_ratio) { #>
                            <span class="gf-preview-meta">DP: {{ data.guardify.dp_ratio }}%</span>
                            <# } #>
                        <# } else { #>
                            <span class="gf-badge gf-badge-success">CLEAR</span>
                        <# } #>
                    </div>
                </div>

                {{! --- Phone History (DP from courier APIs) --- }}
                <div class="gf-preview-card">
                    <div class="gf-preview-card-title">Phone History</div>
                    <div class="gf-preview-card-body">
                        <# if (data.guardify.phone_valid) { #>
                            <button type="button" class="button button-small gf-qv-dp-btn" data-phone="{{ data.guardify.phone }}">
                                Load DP
                            </button>
                            <span class="gf-qv-dp-result"></span>
                        <# } else { #>
                            <span style="color:#9ca3af;">Invalid phone</span>
                        <# } #>
                    </div>
                </div>

                {{! --- Courier Status --- }}
                <# if (data.guardify.courier_consignment) { #>
                <div class="gf-preview-card">
                    <div class="gf-preview-card-title">Courier</div>
                    <div class="gf-preview-card-body">
                        <div style="font-weight:600;text-transform:capitalize;">{{ data.guardify.courier_provider }}</div>
                        <div style="font-family:monospace;font-size:11px;color:#6b7280;">{{ data.guardify.courier_consignment }}</div>
                        <# var cs = data.guardify.courier_status || 'pending';
                           var csColor = '#d97706';
                           if (cs === 'delivered' || cs === 'Delivered') csColor = '#16a34a';
                           if (cs === 'returned' || cs === 'cancelled') csColor = '#dc2626';
                        #>
                        <div style="color:{{ csColor }};font-weight:600;margin-top:2px;">{{ cs }}</div>
                    </div>
                </div>
                <# } #>
            </div>

            {{! --- Additional Info --- }}
            <div class="gf-preview-footer">
                <# if (data.guardify.ip_address) { #>
                <span class="gf-preview-tag">IP: {{ data.guardify.ip_address }}</span>
                <# } #>
                <# if (data.guardify.device_id) { #>
                <span class="gf-preview-tag">Device: {{ data.guardify.device_id.substring(0, 12) }}...</span>
                <# } #>
            </div>
        </div>
        <# } #>
        <?php
    }

    /**
     * Enqueue styles and scripts for the preview modal on orders page.
     */
    public function enqueue_assets($hook) {
        if ('edit.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }
        if ('edit.php' === $hook && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order')) {
            return;
        }

        // CSS
        wp_add_inline_style('woocommerce_admin_styles', '
            .gf-preview-section {
                border-top: 1px solid #e5e7eb;
                padding: 16px;
                margin-top: 8px;
            }
            .gf-preview-header {
                font-size: 13px;
                color: #111827;
                margin-bottom: 12px;
                padding-bottom: 8px;
                border-bottom: 1px solid #f3f4f6;
            }
            .gf-preview-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }
            .gf-preview-card {
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                padding: 10px;
            }
            .gf-preview-card-title {
                font-size: 11px;
                font-weight: 600;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin-bottom: 6px;
            }
            .gf-preview-card-body {
                font-size: 13px;
                color: #111827;
            }
            .gf-preview-stat {
                font-size: 22px;
                font-weight: 700;
                color: #111827;
                line-height: 1;
            }
            .gf-preview-label {
                font-size: 12px;
                color: #6b7280;
                margin-left: 4px;
            }
            .gf-preview-meta {
                font-size: 11px;
                color: #6b7280;
                margin-top: 4px;
                display: flex;
                gap: 8px;
            }
            .gf-preview-footer {
                margin-top: 10px;
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
            .gf-preview-tag {
                font-size: 11px;
                color: #6b7280;
                background: #f3f4f6;
                padding: 2px 8px;
                border-radius: 4px;
                font-family: monospace;
            }
            .gf-badge {
                display: inline-block;
                font-size: 11px;
                font-weight: 700;
                padding: 2px 8px;
                border-radius: 4px;
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .gf-badge-success { background: #dcfce7; color: #16a34a; }
            .gf-badge-warning { background: #fef3c7; color: #d97706; }
            .gf-badge-danger  { background: #fee2e2; color: #dc2626; }
        ');

        // JS for lazy-loading DP data in preview modal
        wp_add_inline_script('jquery', "
            jQuery(function($){
                $(document).on('click', '.gf-qv-dp-btn', function(){
                    var btn = $(this);
                    var phone = btn.data('phone');
                    var result = btn.siblings('.gf-qv-dp-result');
                    btn.hide();
                    result.html('<em style=\"font-size:12px;color:#6b7280;\">Loading...</em>');
                    $.post(ajaxurl, {
                        action: 'guardify_phone_history',
                        order_id: 0,
                        phone: phone,
                        _ajax_nonce: '" . wp_create_nonce('guardify_nonce') . "'
                    }, function(r){
                        if (r.success) {
                            result.html(r.data.html);
                        } else {
                            result.html('<span style=\"color:#dc2626;font-size:12px;\">' + (r.data || 'Error') + '</span>');
                            btn.show();
                        }
                    }).fail(function(){
                        result.html('<span style=\"color:#dc2626;font-size:12px;\">Error</span>');
                        btn.show();
                    });
                });
            });
        ");
    }
}
