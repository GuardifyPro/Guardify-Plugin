<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone Sync — Silently syncs unique phone numbers from WooCommerce
 * orders to the Guardify Engine for delivery performance database enrichment.
 *
 * Uses WP-Cron for non-blocking background processing.
 * Processes newest orders first. Sends phones in small batches.
 */
class Guardify_Phone_Sync {

    private static $instance = null;

    /** WP option key tracking the last synced order ID */
    const OFFSET_KEY   = 'guardify_phone_sync_offset';
    /** WP option for tracking sync completion */
    const COMPLETE_KEY = 'guardify_phone_sync_complete';
    /** Cron hook name */
    const CRON_HOOK    = 'guardify_phone_sync_cron';
    /** Phones per batch — keeps each cron run lightweight */
    const BATCH_SIZE   = 50;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Schedule cron if not already scheduled
        add_action('init', [$this, 'schedule_cron']);

        // Register the cron callback
        add_action(self::CRON_HOOK, [$this, 'run_sync']);

        // Re-schedule on plugin activation
        register_activation_hook(GUARDIFY_FILE, [$this, 'on_activate']);

        // Clean up on deactivation
        register_deactivation_hook(GUARDIFY_FILE, [$this, 'on_deactivate']);
    }

    /**
     * Schedule a recurring cron event (every 5 minutes).
     */
    public function schedule_cron() {
        // Register custom interval first (must exist before scheduling)
        add_filter('cron_schedules', function ($schedules) {
            $schedules['guardify_five_minutes'] = [
                'interval' => 300,
                'display'  => 'Every 5 minutes',
            ];
            return $schedules;
        });

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'guardify_five_minutes', self::CRON_HOOK);
        }
    }

    public function on_activate() {
        // Reset sync state so it starts fresh
        delete_option(self::COMPLETE_KEY);
    }

    public function on_deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /**
     * Main sync routine — runs via WP-Cron in background.
     *
     * Strategy: Query orders in batches ordered by ID DESC (newest first).
     * Extract unique phone numbers, send to engine.
     * Track progress with offset to resume where we left off.
     */
    public function run_sync() {
        // Prevent overlapping runs — acquire a 4-minute lock
        if (get_transient('guardify_phone_sync_lock')) {
            return;
        }
        set_transient('guardify_phone_sync_lock', 1, 4 * MINUTE_IN_SECONDS);

        // Skip if already completed full historical sync
        if (get_option(self::COMPLETE_KEY) === 'yes') {
            // After full sync, only sync recent orders (last 24h)
            $this->sync_recent();
            delete_transient('guardify_phone_sync_lock');
            return;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            delete_transient('guardify_phone_sync_lock');
            return;
        }

        $offset = (int) get_option(self::OFFSET_KEY, 0);

        // Get a batch of orders, newest first
        $orders = wc_get_orders([
            'limit'   => self::BATCH_SIZE,
            'offset'  => $offset,
            'orderby' => 'ID',
            'order'   => 'DESC',
            'status'  => ['wc-completed', 'wc-processing', 'wc-on-hold', 'wc-cancelled', 'wc-refunded'],
            'return'  => 'objects',
        ]);

        if (empty($orders)) {
            // All orders processed — mark complete
            update_option(self::COMPLETE_KEY, 'yes');
            delete_transient('guardify_phone_sync_lock');
            return;
        }

        $phones = [];
        foreach ($orders as $order) {
            $phone = $order->get_billing_phone();
            if (empty($phone)) {
                continue;
            }
            $phone = preg_replace('/[\s\-]/', '', $phone);
            $phone = preg_replace('/^\+?88/', '', $phone);

            if (preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $phones[] = $phone;
            }
        }

        // Deduplicate within batch
        $phones = array_values(array_unique($phones));

        if (!empty($phones)) {
            $api->post_async('/api/v1/phone-sync', [
                'phones' => $phones,
            ]);
        }

        // Advance offset
        update_option(self::OFFSET_KEY, $offset + self::BATCH_SIZE);
        delete_transient('guardify_phone_sync_lock');
    }

    /**
     * After historical sync is done, only sync orders from the last 24 hours.
     * This catches new orders placed since the last run.
     */
    private function sync_recent() {
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        $orders = wc_get_orders([
            'limit'      => self::BATCH_SIZE,
            'orderby'    => 'ID',
            'order'      => 'DESC',
            'date_after'  => gmdate('Y-m-d H:i:s', strtotime('-24 hours')),
            'status'     => ['wc-completed', 'wc-processing', 'wc-on-hold'],
            'return'     => 'objects',
        ]);

        if (empty($orders)) {
            return;
        }

        $phones = [];
        foreach ($orders as $order) {
            $phone = $order->get_billing_phone();
            if (empty($phone)) {
                continue;
            }
            $phone = preg_replace('/[\s\-]/', '', $phone);
            $phone = preg_replace('/^\+?88/', '', $phone);

            if (preg_match('/^01[3-9]\d{8}$/', $phone)) {
                $phones[] = $phone;
            }
        }

        $phones = array_values(array_unique($phones));

        if (!empty($phones)) {
            $api->post_async('/api/v1/phone-sync', [
                'phones' => $phones,
            ]);
        }
    }
}
