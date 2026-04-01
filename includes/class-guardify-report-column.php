<?php
defined('ABSPATH') || exit;

/**
 * Guardify Report Column — Shows a compact courier delivery summary
 * (progress bar, DP ratio, parcel stats) in the WC orders list.
 * Auto-loads via AJAX after page render — no click needed.
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
                $new['gf_report'] = 'Courier Report';
            }
        }
        if (!isset($new['gf_report'])) {
            $new['gf_report'] = 'Courier Report';
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
     * Render a loading placeholder — JS will auto-load real data.
     */
    private function output_container($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || empty($order->get_billing_phone())) {
            echo '<span class="gf-rc-muted">—</span>';
            return;
        }

        echo '<div class="gf-rc-wrap" data-order-id="' . esc_attr($order_id) . '">';
        echo '<div class="gf-rc-loading"></div>';
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
            wp_send_json_success(['html' => '<span class="gf-rc-new">নতুন কাস্টমার</span>']);
        }

        $data      = isset($result['data']) ? $result['data'] : $result;
        $dp        = (float) ($data['dp_ratio'] ?? 0);
        $total     = (int) ($data['total_parcels'] ?? 0);
        $delivered = (int) ($data['total_delivered'] ?? 0);
        $cancelled = (int) ($data['total_cancelled'] ?? 0);
        $returned  = (int) ($data['total_returned'] ?? 0);
        $failed    = $cancelled + $returned;
        $risk      = $data['risk_level'] ?? 'unknown';

        // Determine color variant
        $variant = $dp >= 80 ? 'success' : ($dp >= 50 ? 'warning' : 'danger');
        $risk_label = $risk === 'low' ? 'Low' : ($risk === 'medium' ? 'Medium' : 'High');

        $html = '<div class="gf-rc-report">';

        // Risk badge + DP%
        $html .= '<div class="gf-rc-header">';
        $html .= '<span class="gf-rc-risk gf-rc-risk-' . esc_attr($risk) . '">' . esc_html($risk_label) . '</span>';
        $html .= '<span class="gf-rc-dp gf-rc-dp-' . $variant . '">' . number_format($dp, 0) . '%</span>';
        $html .= '</div>';

        // Progress bar
        $html .= '<div class="gf-rc-bar">';
        $html .= '<div class="gf-rc-bar-fill gf-rc-fill-' . $variant . '" style="width:' . min($dp, 100) . '%"></div>';
        $html .= '</div>';

        // Stats row: ALL | DLVD | CANCL
        $html .= '<div class="gf-rc-stats">';
        $html .= '<span>ALL: ' . $total . '</span>';
        $html .= '<span class="gf-rc-divider">|</span>';
        $html .= '<span class="gf-rc-stat-ok">DLVD: ' . $delivered . '</span>';
        if ($failed > 0) {
            $html .= '<span class="gf-rc-divider">|</span>';
            $html .= '<span class="gf-rc-stat-fail">CANCL: ' . $failed . '</span>';
        }
        $html .= '</div>';

        // Provider breakdown
        if (!empty($data['providers'])) {
            $html .= '<div class="gf-rc-providers">';
            foreach ($data['providers'] as $p) {
                $pName  = ucfirst($p['provider'] ?? '');
                $pTotal = (int) ($p['total_parcels'] ?? 0);
                $pDel   = (int) ($p['total_delivered'] ?? 0);
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

        // Auto-load JS — queues all visible orders and fetches with concurrency
        wp_add_inline_script('jquery', "
jQuery(function($){
    var queue = [], running = 0, MAX = 3, nonce = '{$nonce}';

    function process() {
        while (running < MAX && queue.length) {
            var el = queue.shift();
            load(el);
        }
    }

    function load(el) {
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
            process();
        });
    }

    $('.gf-rc-wrap').each(function(){ queue.push($(this)); });
    process();
});
        ");
    }

    /**
     * Inline CSS for the report column.
     */
    private function get_column_css() {
        return "
/* ─── Courier Report Column ────────────────────────────────── */
.column-gf_report { width: 150px; }
.gf-rc-muted { color: #9ca3af; font-size: 12px; }

/* Container */
.gf-rc-wrap {
    min-width: 120px; padding: 6px;
    display: flex; flex-direction: column; align-items: center;
}

/* Loading bar animation */
.gf-rc-loading {
    width: 100%; max-width: 110px; height: 5px;
    background: #e2e8f0; border-radius: 3px;
    overflow: hidden; position: relative;
}
.gf-rc-loading::before {
    content: ''; position: absolute; top: 0; left: 0;
    width: 100%; height: 100%;
    background: linear-gradient(90deg, #6366f1, #4f46e5);
    animation: gf-rc-slide 1.5s ease-in-out infinite;
}
@keyframes gf-rc-slide {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(0); }
    100% { transform: translateX(100%); }
}

/* Report card */
.gf-rc-report { width: 100%; }

/* Header: risk badge + DP% */
.gf-rc-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 5px;
}
.gf-rc-dp {
    font-size: 15px; font-weight: 700; line-height: 1;
}
.gf-rc-dp-success { color: #16a34a; }
.gf-rc-dp-warning { color: #d97706; }
.gf-rc-dp-danger  { color: #dc2626; }

/* Risk badge */
.gf-rc-risk {
    font-size: 9px; font-weight: 600; text-transform: uppercase;
    padding: 2px 7px; border-radius: 9px; letter-spacing: 0.4px;
}
.gf-rc-risk-low     { background: #dcfce7; color: #15803d; }
.gf-rc-risk-medium  { background: #fef3c7; color: #92400e; }
.gf-rc-risk-high    { background: #fee2e2; color: #991b1b; }
.gf-rc-risk-unknown { background: #f3f4f6; color: #6b7280; }

/* Progress bar */
.gf-rc-bar {
    background: #e2e8f0; border-radius: 10px;
    height: 6px; overflow: hidden; margin-bottom: 6px;
}
.gf-rc-bar-fill {
    height: 100%; width: 0; border-radius: 10px;
    transition: width 0.6s ease;
}
.gf-rc-fill-success { background: linear-gradient(90deg, #22c55e, #16a34a); }
.gf-rc-fill-warning { background: linear-gradient(90deg, #fbbf24, #d97706); }
.gf-rc-fill-danger  { background: linear-gradient(90deg, #f87171, #dc2626); }

/* Stats row */
.gf-rc-stats {
    font-size: 11px; color: #64748b;
    display: flex; align-items: center; justify-content: center;
    gap: 2px; line-height: 1; white-space: nowrap;
}
.gf-rc-divider { color: #cbd5e1; margin: 0 1px; }
.gf-rc-stat-ok { color: #22c55e; font-weight: 500; }
.gf-rc-stat-fail { color: #ef4444; font-weight: 500; }

/* Providers */
.gf-rc-providers {
    margin-top: 4px; padding-top: 4px;
    border-top: 1px solid #e5e7eb;
    display: flex; flex-wrap: wrap; gap: 4px; justify-content: center;
}
.gf-rc-prov {
    font-size: 10px; color: #6b7280;
    background: #f1f5f9; padding: 1px 5px; border-radius: 3px;
}

/* New customer */
.gf-rc-new {
    display: inline-block; font-size: 10px; font-weight: 600;
    color: #6366f1; background: #eef2ff; padding: 2px 10px;
    border-radius: 9px;
}

/* Error */
.gf-rc-err { font-size: 11px; color: #dc2626; }
";
    }
}
