<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone Sync — Continuously syncs unique phone numbers from WooCommerce
 * orders to the Guardify Engine for delivery performance database enrichment.
 *
 * Uses WP-Cron for non-blocking background processing.
 * Processes orders in large batches, tracks progress by order ID.
 * Multiple batches per cron run for fast historical sync.
 */
class Guardify_Phone_Sync {

    private static $instance = null;

    /** WP option: highest order ID that has been synced */
    const LAST_ORDER_KEY = 'guardify_phone_sync_last_order_id';
    /** WP option: total orders count at last check */
    const TOTAL_KEY      = 'guardify_phone_sync_total_orders';
    /** WP option: total phones sent to engine */
    const SENT_KEY       = 'guardify_phone_sync_phones_sent';
    /** WP option: total orders scanned so far */
    const SCANNED_KEY    = 'guardify_phone_sync_orders_scanned';
    /** WP option: flag indicating full historical sync is done */
    const COMPLETE_KEY   = 'guardify_phone_sync_complete';
    /** Cron hook name */
    const CRON_HOOK      = 'guardify_phone_sync_cron';
    /** Max phones per API call (engine limit is 200) */
    const BATCH_SIZE     = 200;
    /** Max batches per single cron run (to avoid PHP timeout) */
    const MAX_BATCHES    = 20;
    /** Max seconds a single cron run can take */
    const MAX_RUNTIME    = 55;

    // Legacy keys to clean up
    const LEGACY_OFFSET_KEY = 'guardify_phone_sync_offset';

    /** WP option: timestamp when last resync check was done */
    const RESYNC_CHECK_KEY = 'guardify_phone_sync_resync_checked';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'schedule_cron']);
        add_action(self::CRON_HOOK, [$this, 'run_sync']);
        register_activation_hook(GUARDIFY_FILE, [$this, 'on_activate']);
        register_deactivation_hook(GUARDIFY_FILE, [$this, 'on_deactivate']);

        // AJAX for manual sync trigger from settings
        add_action('wp_ajax_guardify_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_guardify_sync_status', [$this, 'ajax_sync_status']);
    }

    /**
     * Schedule recurring cron — every 2 minutes for faster throughput.
     */
    public function schedule_cron() {
        add_filter('cron_schedules', function ($schedules) {
            $schedules['guardify_two_minutes'] = [
                'interval' => 120,
                'display'  => 'Every 2 minutes',
            ];
            return $schedules;
        });

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'guardify_two_minutes', self::CRON_HOOK);
        }

        // Migrate from legacy 5-minute schedule
        $legacy = wp_next_scheduled('guardify_phone_sync_cron');
        if ($legacy) {
            // Already scheduled under our hook — check interval
            $scheduled = wp_get_scheduled_event(self::CRON_HOOK);
            if ($scheduled && $scheduled->schedule === 'guardify_five_minutes') {
                wp_clear_scheduled_hook(self::CRON_HOOK);
                wp_schedule_event(time(), 'guardify_two_minutes', self::CRON_HOOK);
            }
        }

        // Clean up legacy option
        delete_option(self::LEGACY_OFFSET_KEY);
    }

    public function on_activate() {
        // Don't reset progress — allow resumption after reactivation
    }

    public function on_deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Main sync routine — runs via WP-Cron.
     * Processes MULTIPLE batches per run for fast historical sync.
     * Tracks the lowest unsynced order ID and works upward (oldest first).
     */
    public function run_sync() {
        // Prevent overlapping runs
        if (get_transient('guardify_phone_sync_lock')) {
            return;
        }
        set_transient('guardify_phone_sync_lock', 1, 3 * MINUTE_IN_SECONDS);

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            delete_transient('guardify_phone_sync_lock');
            return;
        }

        // Check if admin requested a full resync (every 5 min max)
        $this->check_resync_trigger($api);

        $start_time = time();
        $batches_done = 0;
        $is_complete = get_option(self::COMPLETE_KEY) === 'yes';

        if ($is_complete) {
            // Already synced all historical — just sync new orders
            $this->sync_new_orders($api);
            delete_transient('guardify_phone_sync_lock');
            return;
        }

        // Historical sync: process multiple batches per run
        $last_synced_id = (int) get_option(self::LAST_ORDER_KEY, 0);

        while ($batches_done < self::MAX_BATCHES) {
            // Check time limit
            if ((time() - $start_time) >= self::MAX_RUNTIME) {
                break;
            }

            $result = $this->sync_batch($api, $last_synced_id);
            if ($result === false) {
                break; // API error — stop and retry next cron
            }
            if ($result === 0) {
                // No more orders — historical sync complete
                update_option(self::COMPLETE_KEY, 'yes');
                break;
            }

            $last_synced_id = $result; // Returns the highest order ID in this batch
            $batches_done++;
        }

        // Update total orders count periodically
        if ($batches_done > 0 || !get_option(self::TOTAL_KEY)) {
            $this->update_total_count();
        }

        delete_transient('guardify_phone_sync_lock');
    }

    /**
     * Sync a single batch of orders with ID > $after_id.
     *
     * @param Guardify_API $api
     * @param int $after_id Sync orders with ID greater than this
     * @return int|false Highest order ID synced, 0 if no more orders, false on error
     */
    private function sync_batch($api, $after_id) {
        // Get orders with ID > last synced, oldest first
        $args = [
            'limit'   => self::BATCH_SIZE,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'status'  => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-pending'],
            'return'  => 'objects',
        ];

        // Use ID comparison for efficient pagination
        if ($after_id > 0) {
            // WC HPOS-compatible: use date_created filter with a small overlap
            // But more reliably, we can just use the 'exclude' or custom query
            // For HPOS compatibility, best approach: custom query via 'field_query'
            add_filter('woocommerce_order_data_store_cpt_get_orders_query', function ($query, $query_vars) use ($after_id) {
                global $wpdb;
                if (!empty($query_vars['guardify_after_id'])) {
                    // Works with both CPT and HPOS
                    $query['where'] .= $wpdb->prepare(' AND ID > %d', $after_id);
                }
                return $query;
            }, 10, 2);
            $args['guardify_after_id'] = $after_id;

            // Also register HPOS-compatible filter
            add_filter('woocommerce_orders_table_query_clauses', function ($clauses) use ($after_id) {
                global $wpdb;
                $clauses['where'] .= $wpdb->prepare(' AND wc_orders.id > %d', $after_id);
                return $clauses;
            }, 10, 1);
        }

        $orders = wc_get_orders($args);

        // Remove filters to avoid side effects
        remove_all_filters('woocommerce_order_data_store_cpt_get_orders_query');
        remove_all_filters('woocommerce_orders_table_query_clauses');

        if (empty($orders)) {
            return 0; // No more orders
        }

        $phones = [];
        $highest_id = $after_id;

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            if ($order_id > $highest_id) {
                $highest_id = $order_id;
            }

            $phone = $this->normalize_phone($order->get_billing_phone());
            if ($phone) {
                $phones[$phone] = true; // deduplicate
            }
        }

        $phones = array_keys($phones);

        if (!empty($phones)) {
            $result = $api->post('/api/v1/phone-sync', [
                'phones' => array_values($phones),
            ]);

            // Check if API call succeeded
            if (isset($result['success']) && $result['success'] === false) {
                return false; // API error — don't advance
            }

            // Track total phones sent
            $sent = (int) get_option(self::SENT_KEY, 0);
            update_option(self::SENT_KEY, $sent + count($phones), false);
        }

        // Advance the sync pointer
        update_option(self::LAST_ORDER_KEY, $highest_id, false);

        // Track orders scanned
        $scanned = (int) get_option(self::SCANNED_KEY, 0);
        update_option(self::SCANNED_KEY, $scanned + count($orders), false);

        return $highest_id;
    }

    /**
     * Sync only orders created after the last synced order.
     * Called after historical sync is complete.
     */
    private function sync_new_orders($api) {
        $last_synced_id = (int) get_option(self::LAST_ORDER_KEY, 0);
        if ($last_synced_id <= 0) {
            // Safety: if somehow complete but no ID tracked, re-trigger full sync
            delete_option(self::COMPLETE_KEY);
            return;
        }

        $result = $this->sync_batch($api, $last_synced_id);
        if ($result > 0) {
            // There were new orders — update count
            $this->update_total_count();
        }
    }

    /**
     * Check if admin requested a full resync from the portal.
     * Polls the engine's check-resync endpoint. If resync was requested
     * after our last ack, reset sync progress and start over.
     */
    private function check_resync_trigger($api) {
        // Only check every 5 minutes to avoid excessive API calls
        $last_check = (int) get_option(self::RESYNC_CHECK_KEY, 0);
        if ((time() - $last_check) < 300) {
            return;
        }
        update_option(self::RESYNC_CHECK_KEY, time(), false);

        $result = $api->get('/api/v1/phone-sync/check-resync');
        if (empty($result) || empty($result['data'])) {
            // May be wrapped in {success:true, data: {...}}
            $data = isset($result['data']) ? $result['data'] : $result;
        } else {
            $data = $result['data'];
        }

        if (!empty($data['resync_requested'])) {
            // Reset sync progress — re-sync all orders from scratch
            delete_option(self::LAST_ORDER_KEY);
            delete_option(self::COMPLETE_KEY);
            delete_option(self::SCANNED_KEY);
            delete_option(self::SENT_KEY);

            // Acknowledge the resync so we don't keep resetting
            $api->post('/api/v1/phone-sync/ack-resync', []);
        }
    }

    /**
     * Normalize a phone number to 01XXXXXXXXX format.
     */
    private function normalize_phone($phone) {
        if (empty($phone)) {
            return null;
        }
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        $phone = preg_replace('/^\+?88/', '', $phone);
        if (preg_match('/^01[3-9]\d{8}$/', $phone)) {
            return $phone;
        }
        return null;
    }

    /**
     * Update the stored total order count.
     */
    private function update_total_count() {
        $count = (int) wc_get_orders([
            'limit'  => 1,
            'return' => 'ids',
            'status' => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded', 'wc-pending'],
            'paginate' => true,
        ])->total;
        update_option(self::TOTAL_KEY, $count, false);
    }

    /**
     * Get current sync status.
     */
    public function get_status() {
        return [
            'last_order_id'   => (int) get_option(self::LAST_ORDER_KEY, 0),
            'total_orders'    => (int) get_option(self::TOTAL_KEY, 0),
            'orders_scanned'  => (int) get_option(self::SCANNED_KEY, 0),
            'phones_sent'     => (int) get_option(self::SENT_KEY, 0),
            'is_complete'     => get_option(self::COMPLETE_KEY) === 'yes',
        ];
    }

    // ─── AJAX Handlers ────────────────────────────────────────────────────

    /**
     * Manual sync trigger from admin (processes up to 50 batches immediately).
     */
    public function ajax_manual_sync() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'অনুমতি নেই']);
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error(['message' => 'API সংযুক্ত নেই']);
        }

        $start_time = time();
        $batches_done = 0;
        $total_phones = 0;
        $last_synced_id = (int) get_option(self::LAST_ORDER_KEY, 0);

        // Reset complete flag if force syncing
        if (isset($_POST['force']) && $_POST['force'] === 'true') {
            delete_option(self::COMPLETE_KEY);
        }

        while ($batches_done < 50 && (time() - $start_time) < self::MAX_RUNTIME) {
            $result = $this->sync_batch($api, $last_synced_id);
            if ($result === false || $result === 0) {
                if ($result === 0) {
                    update_option(self::COMPLETE_KEY, 'yes');
                }
                break;
            }
            $last_synced_id = $result;
            $batches_done++;
        }

        $this->update_total_count();
        $status = $this->get_status();
        $status['batches_processed'] = $batches_done;
        wp_send_json_success($status);
    }

    /**
     * Get sync status via AJAX.
     */
    public function ajax_sync_status() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'অনুমতি নেই']);
        }
        wp_send_json_success($this->get_status());
    }
}
