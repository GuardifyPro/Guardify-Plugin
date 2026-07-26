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

        // Clear backup crons
        wp_clear_scheduled_hook('guardify_scheduled_backup');
        wp_clear_scheduled_hook('guardify_check_pending_backup');
        wp_clear_scheduled_hook('guardify_backup_worker');

        // A dump interrupted by deactivation leaves a temp file and a job row behind. The
        // file is the larger problem: it is a full copy of the database sitting in the
        // uploads directory, and nothing will ever come back to remove it.
        $job = get_option('guardify_backup_job', null);
        if (is_array($job) && !empty($job['path']) && file_exists($job['path'])) {
            wp_delete_file($job['path']);
        }
        delete_option('guardify_backup_job');
        delete_transient('guardify_backup_slice_lock');

        flush_rewrite_rules();
    }
}
