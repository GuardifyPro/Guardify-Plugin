<?php
defined('ABSPATH') || exit;

/**
 * Guardify Send to Courier — Send WooCommerce orders to Steadfast/Pathao via Guardify Engine.
 */
class Guardify_Send_Courier {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Meta box on order edit page
        add_action('add_meta_boxes', [$this, 'add_meta_box']);

        // AJAX handlers
        add_action('wp_ajax_guardify_send_to_courier', [$this, 'ajax_send_to_courier']);
        add_action('wp_ajax_guardify_check_courier_status', [$this, 'ajax_check_courier_status']);
        add_action('wp_ajax_guardify_courier_balance', [$this, 'ajax_courier_balance']);
        add_action('wp_ajax_guardify_pathao_locations', [$this, 'ajax_pathao_locations']);

        // Bulk action
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_action']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'add_bulk_action']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_action'], 10, 3);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle_bulk_action'], 10, 3);

        // Order list column (CPT + HPOS)
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column']);
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column_cpt'], 10, 2);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_column_hpos'], 10, 2);

        // Enqueue scripts on orders page
        add_action('admin_enqueue_scripts', [$this, 'enqueue_order_list_scripts']);
    }

    public function add_meta_box() {
        $screen = $this->get_order_screen();
        if ($screen) {
            add_meta_box(
                'guardify-send-courier',
                'Guardify — Send to Courier',
                [$this, 'render_meta_box'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box($post_or_order) {
        $order = $this->get_order_from($post_or_order);
        if (!$order) {
            echo '<p>Order not found.</p>';
            return;
        }

        $order_id       = $order->get_id();
        $consignment    = $order->get_meta('_guardify_consignment_id');
        $courier        = $order->get_meta('_guardify_courier_provider');
        $courier_status = $order->get_meta('_guardify_courier_status');
        $nonce          = wp_create_nonce('guardify_courier_nonce');

        // Already sent
        if ($consignment) {
            ?>
            <div id="gf-courier-box" data-nonce="<?php echo esc_attr($nonce); ?>" data-order-id="<?php echo esc_attr($order_id); ?>">
                <div style="padding:4px 0;">
                    <div class="gf-courier-row"><span>Courier</span><strong><?php echo esc_html(ucfirst($courier)); ?></strong></div>
                    <div class="gf-courier-row"><span>Consignment</span><strong style="font-family:monospace; font-size:12px;"><?php echo esc_html($consignment); ?></strong></div>
                    <div class="gf-courier-row"><span>Status</span><strong id="gf-cs-status"><?php echo esc_html($courier_status ?: 'pending'); ?></strong></div>
                </div>
                <button type="button" class="button button-small" id="gf-cs-refresh" style="margin-top:8px;">
                    Refresh Status
                </button>
            </div>
            <script>
            jQuery(function($){
                $('#gf-cs-refresh').on('click', function(){
                    var btn = $(this);
                    btn.prop('disabled', true).text('Checking...');
                    $.post(ajaxurl, {
                        action: 'guardify_check_courier_status',
                        _wpnonce: '<?php echo esc_js($nonce); ?>',
                        order_id: <?php echo (int) $order_id; ?>,
                        consignment_id: '<?php echo esc_js($consignment); ?>',
                        provider: '<?php echo esc_js($courier); ?>'
                    }, function(res){
                        btn.prop('disabled', false).text('Refresh Status');
                        if (res.success && res.data.status) {
                            $('#gf-cs-status').text(res.data.status);
                        }
                    }).fail(function(){ btn.prop('disabled', false).text('Refresh Status'); });
                });
            });
            </script>
            <?php
            return;
        }

        // Not yet sent — show send form
        $phone   = $order->get_billing_phone();
        $name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
        $address = $order->get_billing_address_1();
        $city    = $order->get_billing_city();
        $total   = $order->get_total();

        $default_provider = get_option('guardify_default_courier', 'steadfast');
        ?>
        <div id="gf-courier-box" data-nonce="<?php echo esc_attr($nonce); ?>" data-order-id="<?php echo esc_attr($order_id); ?>">
            <div style="margin-bottom:8px;">
                <label class="gf-label" style="display:block; margin-bottom:4px; font-size:12px; color:#6b7280;">Select Courier</label>
                <select id="gf-courier-select" style="width:100%;">
                    <option value="steadfast" <?php selected($default_provider, 'steadfast'); ?>>Steadfast</option>
                    <option value="pathao" <?php selected($default_provider, 'pathao'); ?>>Pathao</option>
                </select>
            </div>
            <div style="margin-bottom:8px;">
                <label class="gf-label" style="display:block; margin-bottom:4px; font-size:12px; color:#6b7280;">COD Amount (৳)</label>
                <input type="number" id="gf-courier-cod" value="<?php echo esc_attr($total); ?>" style="width:100%;" step="0.01" />
            </div>
            <div style="margin-bottom:8px;">
                <label class="gf-label" style="display:block; margin-bottom:4px; font-size:12px; color:#6b7280;">Note (optional)</label>
                <input type="text" id="gf-courier-note" value="" style="width:100%;" placeholder="Special instructions..." />
            </div>
            <div id="gf-courier-msg" style="display:none; margin-bottom:8px; font-size:13px;"></div>
            <button type="button" class="button button-primary" id="gf-send-courier-btn" style="width:100%;">
                Send to Courier
            </button>
        </div>
        <script>
        jQuery(function($){
            var box = $('#gf-courier-box');
            var nonce = box.data('nonce');
            var orderId = box.data('order-id');

            $('#gf-send-courier-btn').on('click', function(){
                var btn = $(this);
                var msg = $('#gf-courier-msg');
                btn.prop('disabled', true).text('Sending...');
                msg.hide();

                $.post(ajaxurl, {
                    action: 'guardify_send_to_courier',
                    _wpnonce: nonce,
                    order_id: orderId,
                    provider: $('#gf-courier-select').val(),
                    cod_amount: $('#gf-courier-cod').val(),
                    note: $('#gf-courier-note').val()
                }, function(res){
                    if (res.success) {
                        msg.css('color', '#16a34a').text('✓ Sent to courier. Consignment: ' + (res.data.consignment_id || '')).show();
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        msg.css('color', '#dc2626').text('✗ ' + (res.data || 'Send failed')).show();
                        btn.prop('disabled', false).text('Send to Courier');
                    }
                }).fail(function(){
                    msg.css('color', '#dc2626').text('✗ Server connection failed').show();
                    btn.prop('disabled', false).text('Send to Courier');
                });
            });
        });
        </script>
        <?php
    }

    // --- AJAX: Send to courier ---
    public function ajax_send_to_courier() {
        check_ajax_referer('guardify_courier_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        $cod      = isset($_POST['cod_amount']) ? floatval($_POST['cod_amount']) : 0;
        $note     = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';

        if (!$order_id || !$provider) {
            wp_send_json_error('Select order and courier');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error('Order not found');
        }

        // Check if already sent
        if ($order->get_meta('_guardify_consignment_id')) {
            wp_send_json_error('Order already sent to courier');
        }

        $api = new Guardify_API();
        $actual_cod = $cod > 0 ? $cod : (float) $order->get_total();
        $result = $api->post('/api/v1/courier/send', [
            'provider'          => $provider,
            'order_id'          => (string) $order_id,
            'recipient_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'recipient_phone'   => $order->get_billing_phone(),
            'recipient_address' => $order->get_billing_address_1() . ', ' . $order->get_billing_city(),
            'cod_amount'        => $actual_cod,
            'note'              => $note,
        ]);

        if (!empty($result['success']) && isset($result['data'])) {
            $data = $result['data'];
            $consignment_id = isset($data['consignment_id']) ? $data['consignment_id'] : '';
            $status         = isset($data['status']) ? $data['status'] : 'pending';

            $order->update_meta_data('_guardify_consignment_id', $consignment_id);
            $order->update_meta_data('_guardify_courier_provider', $provider);
            $order->update_meta_data('_guardify_courier_status', $status);
            $order->save();

            wp_send_json_success([
                'consignment_id' => $consignment_id,
                'status'         => $status,
            ]);
        }

        // Handle non-wrapped response
        if (isset($result['consignment_id'])) {
            $order->update_meta_data('_guardify_consignment_id', $result['consignment_id']);
            $order->update_meta_data('_guardify_courier_provider', $provider);
            $order->update_meta_data('_guardify_courier_status', $result['status'] ?? 'pending');
            $order->save();
            wp_send_json_success($result);
        }

        $error = isset($result['error']['message']) ? $result['error']['message'] : (isset($result['error']) ? $result['error'] : 'Send failed');
        wp_send_json_error($error);
    }

    // --- AJAX: Check status ---
    public function ajax_check_courier_status() {
        check_ajax_referer('guardify_courier_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $order_id       = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $consignment_id = isset($_POST['consignment_id']) ? sanitize_text_field(wp_unslash($_POST['consignment_id'])) : '';
        $provider       = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';

        if (!$consignment_id || !$provider) {
            wp_send_json_error('Missing parameters');
        }

        $api = new Guardify_API();
        $result = $api->get('/api/v1/courier/status', [
            'consignment_id' => $consignment_id,
            'provider'       => $provider,
        ]);

        $status = '';
        if (!empty($result['success']) && isset($result['data']['status'])) {
            $status = $result['data']['status'];
        } elseif (isset($result['status'])) {
            $status = $result['status'];
        }

        if ($status && $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_guardify_courier_status', $status);
                $order->save();
            }
        }

        wp_send_json_success(['status' => $status ?: 'unknown']);
    }

    // --- AJAX: Check balance ---
    public function ajax_courier_balance() {
        check_ajax_referer('guardify_courier_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $provider = isset($_POST['provider']) ? sanitize_text_field(wp_unslash($_POST['provider'])) : '';
        if (!$provider) {
            wp_send_json_error('Provider required');
        }

        $api = new Guardify_API();
        $result = $api->get('/api/v1/courier/balance', ['provider' => $provider]);

        if (!empty($result['success']) && isset($result['data'])) {
            wp_send_json_success($result['data']);
        } elseif (isset($result['balance'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error('Balance check failed');
    }

    // --- AJAX: Pathao locations ---
    public function ajax_pathao_locations() {
        check_ajax_referer('guardify_courier_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $type      = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : '';
        $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;

        $api = new Guardify_API();
        $result = $api->post('/api/v1/courier/locations', [
            'type'      => $type,
            'parent_id' => $parent_id,
        ]);

        if (is_array($result) && !isset($result['error'])) {
            wp_send_json_success($result);
        }

        wp_send_json_error('Failed to load locations');
    }

    // --- Bulk action ---
    public function add_bulk_action($actions) {
        $actions['guardify_send_steadfast'] = 'Guardify → Send to Steadfast';
        $actions['guardify_send_pathao']    = 'Guardify → Send to Pathao';
        return $actions;
    }

    public function handle_bulk_action($redirect_to, $action, $order_ids) {
        $provider = '';
        if ($action === 'guardify_send_steadfast') $provider = 'steadfast';
        if ($action === 'guardify_send_pathao')    $provider = 'pathao';
        if (!$provider) return $redirect_to;

        $sent = 0;
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_meta('_guardify_consignment_id')) continue;

            $api = new Guardify_API();
            $result = $api->post('/api/v1/courier/send', [
                'provider'          => $provider,
                'order_id'          => (string) $order_id,
                'recipient_name'    => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'recipient_phone'   => $order->get_billing_phone(),
                'recipient_address' => $order->get_billing_address_1() . ', ' . $order->get_billing_city(),
                'cod_amount'        => (float) $order->get_total(),
                'note'              => '',
            ]);

            $cid = '';
            if (!empty($result['success']) && isset($result['data']['consignment_id'])) {
                $cid = $result['data']['consignment_id'];
            } elseif (isset($result['consignment_id'])) {
                $cid = $result['consignment_id'];
            }

            if ($cid) {
                $order->update_meta_data('_guardify_consignment_id', $cid);
                $order->update_meta_data('_guardify_courier_provider', $provider);
                $order->update_meta_data('_guardify_courier_status', 'pending');
                $order->save();
                $sent++;
            }
        }

        return add_query_arg('guardify_bulk_sent', $sent, $redirect_to);
    }

    // --- Orders list column ---
    public function add_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['guardify_courier'] = 'Send to Courier';
            }
        }
        return $new;
    }

    /** CPT column render. */
    public function render_column_cpt($column, $post_id) {
        if ($column !== 'guardify_courier') return;
        $this->output_courier_cell($post_id);
    }

    /** HPOS column render. */
    public function render_column_hpos($column, $order) {
        if ($column !== 'guardify_courier') return;
        $this->output_courier_cell($order->get_id());
    }

    /**
     * Output the courier cell with send button or status.
     */
    private function output_courier_cell($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            echo '—';
            return;
        }

        $consignment = $order->get_meta('_guardify_consignment_id');
        $provider    = $order->get_meta('_guardify_courier_provider');
        $status      = $order->get_meta('_guardify_courier_status');

        if ($consignment) {
            // Already sent — show status
            $variant = 'muted';
            $sl = strtolower($status ?: '');
            if (in_array($sl, ['delivered'])) $variant = 'success';
            if (in_array($sl, ['returned', 'cancelled', 'partial_delivered'])) $variant = 'danger';
            if (in_array($sl, ['in_review', 'pending', 'in_transit'])) $variant = 'warning';

            echo '<div class="gf-sc-cell gf-sc-sent">';
            echo '<span class="gf-sc-provider">' . esc_html(ucfirst($provider)) . '</span>';
            echo '<span class="gf-sc-cn">' . esc_html($consignment) . '</span>';
            echo '<span class="gf-sc-status gf-sc-status-' . esc_attr($variant) . '">' . esc_html(ucfirst(str_replace('_', ' ', $status ?: 'pending'))) . '</span>';
            echo '<button type="button" class="gf-sc-refresh" data-order-id="' . esc_attr($order_id) . '" data-cn="' . esc_attr($consignment) . '" data-provider="' . esc_attr($provider) . '" title="Refresh">↻</button>';
            echo '</div>';
            return;
        }

        // Not sent — show send button with COD field
        $default = get_option('guardify_default_courier', 'steadfast');
        $total   = $order->get_total();
        echo '<div class="gf-sc-cell" id="gf-sc-' . esc_attr($order_id) . '">';
        echo '<input type="number" class="gf-sc-cod" value="' . esc_attr($total) . '" step="0.01" min="0" title="COD Amount (৳)" />';
        echo '<button type="button" class="gf-sc-send" data-order-id="' . esc_attr($order_id) . '" data-provider="' . esc_attr($default) . '">Send</button>';
        echo '</div>';
    }

    /**
     * Enqueue column scripts + styles on orders page.
     */
    public function enqueue_order_list_scripts($hook) {
        if ('edit.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }
        if ('edit.php' === $hook && (!isset($_GET['post_type']) || $_GET['post_type'] !== 'shop_order')) {
            return;
        }

        $nonce = wp_create_nonce('guardify_courier_nonce');

        // CSS
        wp_add_inline_style('woocommerce_admin_styles', "
.column-guardify_courier { width: 140px; }
.gf-sc-cell { font-size: 11px; line-height: 1.5; }
.gf-sc-cod {
    display: block; width: 100%; padding: 3px 6px; border: 1px solid #d1d5db; border-radius: 4px;
    font-size: 11px; margin-bottom: 4px; box-sizing: border-box;
}
.gf-sc-cod:focus { border-color: #16a34a; outline: none; }
.gf-sc-send {
    display: inline-block; padding: 4px 14px; border: none; border-radius: 4px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    background: #16a34a; color: #fff; transition: opacity .15s;
}
.gf-sc-send:hover { opacity: .85; }
.gf-sc-send:disabled { opacity: .5; cursor: not-allowed; }
.gf-sc-send.gf-sc-done {
    background: #e5e7eb; color: #374151; cursor: default;
}
.gf-sc-provider { display: block; font-weight: 600; color: #374151; font-size: 12px; }
.gf-sc-cn { display: block; font-family: monospace; font-size: 10px; color: #6b7280; word-break: break-all; }
.gf-sc-status { display: inline-block; font-size: 10px; font-weight: 600; padding: 1px 6px; border-radius: 9px; margin-top: 2px; }
.gf-sc-status-success { background: #dcfce7; color: #15803d; }
.gf-sc-status-warning { background: #fef3c7; color: #92400e; }
.gf-sc-status-danger  { background: #fee2e2; color: #991b1b; }
.gf-sc-status-muted   { background: #f3f4f6; color: #6b7280; }
.gf-sc-refresh {
    border: none; background: none; font-size: 14px; cursor: pointer; color: #6b7280;
    padding: 0 4px; margin-left: 2px; vertical-align: middle;
}
.gf-sc-refresh:hover { color: #374151; }
.gf-sc-err { color: #dc2626; font-size: 11px; display: block; margin-top: 2px; }
        ");

        // JS
        wp_add_inline_script('jquery', "
jQuery(function($){
    var nonce = '{$nonce}';

    // Send button click
    $(document).on('click', '.gf-sc-send', function(){
        var btn = $(this), id = btn.data('order-id'), provider = btn.data('provider');
        var codInput = btn.siblings('.gf-sc-cod');
        var codVal = codInput.length ? parseFloat(codInput.val()) || 0 : 0;
        btn.prop('disabled', true).text('Sending...');
        $.post(ajaxurl, {
            action: 'guardify_send_to_courier',
            _wpnonce: nonce,
            order_id: id,
            provider: provider,
            cod_amount: codVal,
            note: ''
        }, function(r){
            if (r.success) {
                var d = r.data;
                var cell = $('#gf-sc-' + id);
                cell.html(
                    '<span class=\"gf-sc-provider\">' + provider.charAt(0).toUpperCase() + provider.slice(1) + '</span>' +
                    '<span class=\"gf-sc-cn\">' + (d.consignment_id || '') + '</span>' +
                    '<span class=\"gf-sc-status gf-sc-status-warning\">' + (d.status || 'Pending') + '</span>'
                );
            } else {
                btn.prop('disabled', false).text('Send');
                var wrap = btn.parent();
                wrap.find('.gf-sc-err').remove();
                wrap.append('<span class=\"gf-sc-err\">' + (r.data || 'Failed') + '</span>');
            }
        }).fail(function(){
            btn.prop('disabled', false).text('Send');
        });
    });

    // Refresh status click
    $(document).on('click', '.gf-sc-refresh', function(){
        var btn = $(this), cn = btn.data('cn'), provider = btn.data('provider'), id = btn.data('order-id');
        btn.text('⏳');
        $.post(ajaxurl, {
            action: 'guardify_check_courier_status',
            _wpnonce: nonce,
            order_id: id,
            consignment_id: cn,
            provider: provider
        }, function(r){
            btn.text('↻');
            if (r.success && r.data.status) {
                btn.siblings('.gf-sc-status').text(r.data.status.charAt(0).toUpperCase() + r.data.status.slice(1).replace(/_/g,' '));
            }
        }).fail(function(){ btn.text('↻'); });
    });
});
        ");
    }

    // --- Helpers ---
    private function get_order_screen() {
        if (class_exists('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController')) {
            $controller = wc_get_container()->get('Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController');
            if (method_exists($controller, 'custom_orders_table_usage_is_enabled') && $controller->custom_orders_table_usage_is_enabled()) {
                return wc_get_page_screen_id('shop-order');
            }
        }
        return 'shop_order';
    }

    private function get_order_from($post_or_order) {
        if ($post_or_order instanceof WC_Order) return $post_or_order;
        if (is_a($post_or_order, 'WP_Post')) return wc_get_order($post_or_order->ID);
        if (is_numeric($post_or_order)) return wc_get_order($post_or_order);
        return null;
    }
}
