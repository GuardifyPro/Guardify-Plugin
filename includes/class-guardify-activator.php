<?php
defined('ABSPATH') || exit;

class Guardify_Activator {

    public static function activate() {
        // Set default options
        if (false === get_option('guardify_api_key')) {
            add_option('guardify_api_key', '');
        }
        if (false === get_option('guardify_secret_key_enc')) {
            add_option('guardify_secret_key_enc', '');
        }

        // Create custom DB tables
        Guardify_Incomplete_Orders::create_table();
        Guardify_Fraud_Detection::create_tables();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        // Clear scheduled events
        $timestamp = wp_next_scheduled('guardify_cleanup_incomplete');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'guardify_cleanup_incomplete');
        }

        // Clear backup cron
        wp_clear_scheduled_hook('guardify_scheduled_backup');

        flush_rewrite_rules();
    }
}
