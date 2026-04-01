<?php
defined('ABSPATH') || exit;

/**
 * Guardify Report Column — Shows a quick delivery summary report
 * in the WC orders list with block/unblock capability.
 */
class Guardify_Report_Column {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_report_column_enabled', 'yes') !== 'yes') {
            return;
        }

        // CPT orders list
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column'], 10, 2);

        // HPOS orders list
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_column_hpos'], 10, 2);

        // AJAX
        add_action('wp_ajax_guardify_fetch_report', [$this, 'ajax_fetch_report']);

        // Admin scripts
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add column after billing address.
     */
    public function add_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'billing_address' || $key === 'order_total') {
                $new['gf_report'] = 'Guardify Report';
            }
        }
        // Fallback if target column not found
        if (!isset($new['gf_report'])) {
            $new['gf_report'] = 'Guardify Report';
        }
        return $new;
    }

    /**
     * CPT column render.
     */
    public function render_column($column, $post_id) {
        if ($column !== 'gf_report') {
            return;
        }
        $this->output_container($post_id);
    }

    /**
     * HPOS column render.
     */
    public function render_column_hpos($column, $order) {
        if ($column !== 'gf_report') {
            return;
        }
        $this->output_container($order->get_id());
    }

    /**
     * Output a check-report button container.
     */
    private function output_container($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || empty($order->get_billing_phone())) {
            echo '<span style="color:#9ca3af">No phone</span>';
            return;
        }

        echo '<div class="gf-report-wrap">';
        echo '<button type="button" class="button button-small gf-report-btn" data-order-id="' . esc_attr($order_id) . '">View Report</button>';
        echo '<div class="gf-report-result" id="gf-report-' . esc_attr($order_id) . '"></div>';
        echo '</div>';
    }

    /**
     * AJAX: Fetch full delivery report for an order.
     */
    public function ajax_fetch_report() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $order    = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        $phone = $order->get_billing_phone();
        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error('No valid phone');
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error('API not connected');
        }

        $result = $api->get('/api/v1/courier/summary', ['phone' => $phone]);

        if (!isset($result['dp_ratio']) && !isset($result['data']['dp_ratio'])) {
            wp_send_json_error('Data not found');
        }

        // Normalize response
        $data = isset($result['data']) ? $result['data'] : $result;
        $dp       = (float) ($data['dp_ratio'] ?? 0);
        $total    = (int) ($data['total_parcels'] ?? 0);
        $delivered = (int) ($data['total_delivered'] ?? 0);
        $failed   = (int) (($data['total_cancelled'] ?? 0) + ($data['total_returned'] ?? 0));
        $risk     = $data['risk_level'] ?? 'unknown';

        $dp_color = $dp >= 80 ? '#16a34a' : ($dp >= 50 ? '#d97706' : '#dc2626');
        $risk_label = $risk === 'low' ? 'Low' : ($risk === 'medium' ? 'Medium' : 'High');

        // Build compact HTML report
        $html = '<div class="gf-mini-report" style="font-size:12px;line-height:1.6;">';
        $html .= '<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">';
        $html .= '<span style="font-size:18px;font-weight:700;color:' . $dp_color . ';">' . number_format($dp, 1) . '%</span>';
        $html .= '<span style="color:#6b7280;">DP</span>';
        $html .= '</div>';

        // Progress bar
        $html .= '<div style="background:#e5e7eb;border-radius:4px;height:6px;width:100%;margin-bottom:6px;">';
        $html .= '<div style="background:' . $dp_color . ';height:6px;border-radius:4px;width:' . min($dp, 100) . '%;"></div>';
        $html .= '</div>';

        $html .= '<div style="color:#374151;">📦 ' . $total . ' Total &bull; ✅ ' . $delivered . ' Delivered &bull; ❌ ' . $failed . ' Failed</div>';
        $html .= '<div style="color:#6b7280;">Risk: <strong style="color:' . $dp_color . ';">' . esc_html($risk_label) . '</strong></div>';

        // Provider breakdown if available
        if (!empty($data['providers'])) {
            $html .= '<div style="margin-top:4px;border-top:1px solid #e5e7eb;padding-top:4px;">';
            foreach ($data['providers'] as $p) {
                $pName = esc_html(ucfirst($p['provider'] ?? ''));
                $pTotal = (int) ($p['total_parcels'] ?? 0);
                $pDel   = (int) ($p['total_delivered'] ?? 0);
                $pRate  = (float) ($p['success_ratio'] ?? 0);
                $html .= '<div>' . $pName . ': ' . $pDel . '/' . $pTotal . ' (' . number_format($pRate, 0) . '%)</div>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Enqueue admin scripts.
     */
    public function enqueue_scripts($hook) {
        if ('edit.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }
        if ('edit.php' === $hook && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order')) {
            return;
        }

        wp_add_inline_script('jquery', "
            jQuery(function($){
                $(document).on('click','.gf-report-btn',function(){
                    var btn=$(this), id=btn.data('order-id'), res=$('#gf-report-'+id);
                    btn.hide(); res.html('<em>Loading...</em>');
                    $.post(ajaxurl,{action:'guardify_fetch_report',order_id:id,_ajax_nonce:'" . wp_create_nonce('guardify_nonce') . "'},function(r){
                        if(r.success) res.html(r.data.html);
                        else { res.html('<span style=\"color:#dc2626\">'+r.data+'</span>'); btn.show(); }
                    }).fail(function(){ res.html('<span style=\\\"color:#dc2626\\\">Error</span>'); btn.show(); });
                });
            });
        ");
    }
}
