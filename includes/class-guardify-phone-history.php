<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone History Column — Shows DP ratio + order count
 * for each phone number directly in the WC orders list.
 */
class Guardify_Phone_History {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_phone_history_enabled', 'yes') !== 'yes') {
            return;
        }

        // Legacy orders list (CPT)
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column'], 20, 2);

        // HPOS orders list
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_column_hpos'], 20, 2);

        // AJAX for lazy-loading DP data
        add_action('wp_ajax_guardify_phone_history', [$this, 'ajax_phone_history']);

        // Admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Add column to orders table.
     */
    public function add_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['gf_phone_history'] = 'Phone History';
            }
        }
        return $new;
    }

    /**
     * Render column for legacy CPT orders.
     */
    public function render_column($column, $post_id) {
        if ($column !== 'gf_phone_history') {
            return;
        }
        $this->output_column($post_id);
    }

    /**
     * Render column for HPOS orders.
     */
    public function render_column_hpos($column, $order) {
        if ($column !== 'gf_phone_history') {
            return;
        }
        $this->output_column($order->get_id());
    }

    /**
     * Output the column HTML with a load button.
     */
    private function output_column($order_id) {
        echo '<div class="gf-ph-wrap">';
        echo '<button type="button" class="button button-small gf-ph-load" data-order-id="' . esc_attr($order_id) . '">Check</button>';
        echo '<span class="gf-ph-result" id="gf-ph-' . esc_attr($order_id) . '"></span>';
        echo '</div>';
    }

    /**
     * AJAX: Fetch DP data for an order's phone via Engine.
     */
    public function ajax_phone_history() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        // Support direct phone param (from Quick View) or order-based lookup
        $phone = '';
        if (!empty($_POST['phone'])) {
            $phone = sanitize_text_field(wp_unslash($_POST['phone']));
        } elseif ($order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error('Order not found');
            }
            $phone = $order->get_billing_phone();
        }

        $phone = preg_replace('/[\s\-]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);

        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error('No valid phone number');
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error('API not connected');
        }

        $result = $api->get('/api/v1/courier/dp-ratio', ['phone' => $phone]);

        // Normalize wrapped API response
        $d = isset($result['data']) ? $result['data'] : $result;

        // Check for API error
        if (isset($result['success']) && $result['success'] === false) {
            $err_msg = isset($result['error']) ? $result['error'] : 'API error';
            wp_send_json_error($err_msg);
        }

        if (isset($d['dp_ratio'])) {
            $dp    = (float) $d['dp_ratio'];
            $total = isset($d['total']) ? (int) $d['total'] : (isset($d['total_parcels']) ? (int) $d['total_parcels'] : 0);
            $risk  = isset($d['risk_level']) ? $d['risk_level'] : 'unknown';

            if ($total === 0) {
                wp_send_json_success(['html' => '<span style="color:#6b7280;font-size:12px;">No courier data</span>']);
            }

            $color = $dp >= 80 ? '#16a34a' : ($dp >= 50 ? '#d97706' : '#dc2626');

            $html = '<span class="gf-ph-badge" style="color:' . $color . ';font-weight:600;">'
                  . esc_html(number_format($dp, 1)) . '%</span>'
                  . '<span class="gf-ph-meta" style="color:#6b7280;font-size:12px;margin-left:4px;">'
                  . esc_html($total) . ' parcels</span>';

            wp_send_json_success(['html' => $html]);
        }

        wp_send_json_error('DP data not found');
    }

    /**
     * Enqueue admin scripts on orders page.
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
                $(document).on('click','.gf-ph-load',function(){
                    var btn=$(this), id=btn.data('order-id'), res=$('#gf-ph-'+id);
                    btn.hide(); res.html('<em>Loading...</em>');
                    $.post(ajaxurl,{action:'guardify_phone_history',order_id:id,_ajax_nonce:'" . wp_create_nonce('guardify_nonce') . "'},function(r){
                        if(r.success) res.html(r.data.html);
                        else { res.html('<span style=\"color:#dc2626\">'+r.data+'</span>'); btn.show(); }
                    }).fail(function(){ res.html('<span style=\"color:#dc2626\">Error</span>'); btn.show(); });
                });
            });
        ");
    }
}
