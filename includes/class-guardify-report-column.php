<?php
defined('ABSPATH') || exit;

/**
 * Guardify Report Column — Shows a compact delivery summary (DP ratio,
 * risk level, parcel stats) in the WC orders list. Auto-loads via AJAX
 * after page render — no click needed.
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

        // Admin scripts + styles
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add column after order_total.
     */
    public function add_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_total') {
                $new['gf_report'] = 'Guardify';
            }
        }
        if (!isset($new['gf_report'])) {
            $new['gf_report'] = 'Guardify';
        }
        return $new;
    }

    /** CPT column render. */
    public function render_column($column, $post_id) {
        if ($column !== 'gf_report') return;
        $this->output_container($post_id);
    }

    /** HPOS column render. */
    public function render_column_hpos($column, $order) {
        if ($column !== 'gf_report') return;
        $this->output_container($order->get_id());
    }

    /**
     * Render a skeleton placeholder — JS will auto-load real data.
     */
    private function output_container($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || empty($order->get_billing_phone())) {
            echo '<span class="gf-rc-muted">—</span>';
            return;
        }

        echo '<div class="gf-rc-wrap" data-order-id="' . esc_attr($order_id) . '">';
        // Skeleton loader (replaced by AJAX response)
        echo '<div class="gf-rc-skeleton">';
        echo '<span class="gf-rc-skel-bar" style="width:60%"></span>';
        echo '<span class="gf-rc-skel-bar" style="width:90%"></span>';
        echo '<span class="gf-rc-skel-bar" style="width:40%"></span>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * AJAX: Fetch delivery report for an order.
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
            wp_send_json_error('Invalid phone');
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error('Not connected');
        }

        $result = $api->get('/api/v1/courier/summary', ['phone' => $phone]);

        if (!isset($result['dp_ratio']) && !isset($result['data']['dp_ratio'])) {
            // No courier data — return a "new customer" indicator
            wp_send_json_success(['html' => '<span class="gf-rc-new">নতুন</span>']);
        }

        $data      = isset($result['data']) ? $result['data'] : $result;
        $dp        = (float) ($data['dp_ratio'] ?? 0);
        $total     = (int) ($data['total_parcels'] ?? 0);
        $delivered = (int) ($data['total_delivered'] ?? 0);
        $failed    = (int) (($data['total_cancelled'] ?? 0) + ($data['total_returned'] ?? 0));
        $risk      = $data['risk_level'] ?? 'unknown';

        // Determine variant
        $variant = $dp >= 80 ? 'success' : ($dp >= 50 ? 'warning' : 'danger');
        $risk_label = $risk === 'low' ? 'Low' : ($risk === 'medium' ? 'Medium' : 'High');

        $html  = '<div class="gf-rc-card gf-rc-' . $variant . '">';

        // DP + Risk badge row
        $html .= '<div class="gf-rc-top">';
        $html .= '<span class="gf-rc-dp">' . number_format($dp, 0) . '%</span>';
        $html .= '<span class="gf-rc-risk gf-rc-risk-' . esc_attr($risk) . '">' . esc_html($risk_label) . '</span>';
        $html .= '</div>';

        // Progress bar
        $html .= '<div class="gf-rc-bar"><div class="gf-rc-bar-fill gf-rc-fill-' . $variant . '" style="width:' . min($dp, 100) . '%"></div></div>';

        // Stats row
        $html .= '<div class="gf-rc-stats">';
        $html .= '<span title="Total">' . $total . '</span>';
        $html .= '<span class="gf-rc-sep">·</span>';
        $html .= '<span class="gf-rc-stat-ok" title="Delivered">✓' . $delivered . '</span>';
        if ($failed > 0) {
            $html .= '<span class="gf-rc-sep">·</span>';
            $html .= '<span class="gf-rc-stat-fail" title="Failed">✗' . $failed . '</span>';
        }
        $html .= '</div>';

        // Provider breakdown (compact)
        if (!empty($data['providers'])) {
            $html .= '<div class="gf-rc-providers">';
            foreach ($data['providers'] as $p) {
                $pName  = ucfirst($p['provider'] ?? '');
                $pTotal = (int) ($p['total_parcels'] ?? 0);
                $pDel   = (int) ($p['total_delivered'] ?? 0);
                $pRate  = (float) ($p['success_ratio'] ?? 0);
                $html  .= '<span class="gf-rc-prov">' . esc_html($pName) . ' ' . $pDel . '/' . $pTotal . '</span>';
            }
            $html .= '</div>';
        }

        $html .= '</div>';

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Enqueue scripts + inline CSS on orders page.
     */
    public function enqueue_assets($hook) {
        if ('edit.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }
        if ('edit.php' === $hook && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order')) {
            return;
        }

        $nonce = wp_create_nonce('guardify_nonce');

        // CSS for the column
        wp_add_inline_style('woocommerce_admin_styles', $this->get_column_css());

        // Auto-load JS — queues all visible orders and fetches sequentially
        wp_add_inline_script('jquery', "
jQuery(function($){
    var queue = [], running = 0, MAX_CONCURRENT = 3, nonce = '{$nonce}';

    function processQueue() {
        while (running < MAX_CONCURRENT && queue.length > 0) {
            var el = queue.shift();
            fetchReport(el);
        }
    }

    function fetchReport(el) {
        var id = el.data('order-id');
        running++;
        $.post(ajaxurl, {
            action: 'guardify_fetch_report',
            order_id: id,
            _ajax_nonce: nonce
        }, function(r) {
            if (r.success) {
                el.html(r.data.html);
            } else {
                el.html('<span class=\"gf-rc-err\">' + (r.data || 'Error') + '</span>');
            }
        }).fail(function() {
            el.html('<span class=\"gf-rc-err\">Error</span>');
        }).always(function() {
            running--;
            processQueue();
        });
    }

    // Collect all report containers
    $('.gf-rc-wrap').each(function() {
        queue.push($(this));
    });
    processQueue();
});
        ");
    }

    /**
     * Inline CSS for the report column.
     */
    private function get_column_css() {
        return "
/* ─── Guardify Report Column ───────────────────────────────── */
.column-gf_report { width: 140px; }

.gf-rc-muted { color: #9ca3af; font-size: 12px; }

/* Skeleton */
.gf-rc-skeleton { display: flex; flex-direction: column; gap: 5px; }
.gf-rc-skel-bar {
    display: block; height: 10px; border-radius: 4px;
    background: linear-gradient(90deg, #f3f4f6 25%, #e5e7eb 50%, #f3f4f6 75%);
    background-size: 200% 100%;
    animation: gf-rc-shimmer 1.5s infinite;
}
@keyframes gf-rc-shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

/* Card */
.gf-rc-card {
    font-size: 11px; line-height: 1.4;
    padding: 6px 8px; border-radius: 6px;
    border-left: 3px solid transparent;
    background: #f9fafb;
}
.gf-rc-success { border-left-color: #16a34a; }
.gf-rc-warning { border-left-color: #d97706; }
.gf-rc-danger  { border-left-color: #dc2626; }

/* Top row */
.gf-rc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
.gf-rc-dp { font-size: 16px; font-weight: 700; line-height: 1; }
.gf-rc-success .gf-rc-dp { color: #16a34a; }
.gf-rc-warning .gf-rc-dp { color: #d97706; }
.gf-rc-danger  .gf-rc-dp { color: #dc2626; }

/* Risk badge */
.gf-rc-risk {
    font-size: 9px; font-weight: 600; text-transform: uppercase;
    padding: 1px 6px; border-radius: 9px; letter-spacing: 0.5px;
}
.gf-rc-risk-low    { background: #dcfce7; color: #15803d; }
.gf-rc-risk-medium { background: #fef3c7; color: #92400e; }
.gf-rc-risk-high   { background: #fee2e2; color: #991b1b; }
.gf-rc-risk-unknown { background: #f3f4f6; color: #6b7280; }

/* Progress bar */
.gf-rc-bar { height: 4px; border-radius: 2px; background: #e5e7eb; margin-bottom: 4px; }
.gf-rc-bar-fill { height: 4px; border-radius: 2px; transition: width 0.4s ease; }
.gf-rc-fill-success { background: #16a34a; }
.gf-rc-fill-warning { background: #d97706; }
.gf-rc-fill-danger  { background: #dc2626; }

/* Stats */
.gf-rc-stats { color: #374151; font-size: 11px; display: flex; align-items: center; gap: 3px; flex-wrap: wrap; }
.gf-rc-sep { color: #d1d5db; }
.gf-rc-stat-ok { color: #16a34a; }
.gf-rc-stat-fail { color: #dc2626; }

/* Providers */
.gf-rc-providers { margin-top: 3px; padding-top: 3px; border-top: 1px solid #e5e7eb; display: flex; flex-wrap: wrap; gap: 4px; }
.gf-rc-prov { font-size: 10px; color: #6b7280; background: #f3f4f6; padding: 0 4px; border-radius: 3px; }

/* New customer tag */
.gf-rc-new {
    display: inline-block; font-size: 10px; font-weight: 600;
    color: #6366f1; background: #eef2ff; padding: 2px 8px;
    border-radius: 9px;
}

/* Error */
.gf-rc-err { font-size: 11px; color: #dc2626; }
";
    }
}
