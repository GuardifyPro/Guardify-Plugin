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

        // Open the setup wizard on the next admin page load. A merchant who lands on an
        // 819-line settings screen with a dozen toggles, several of which refuse orders,
        // either turns everything on and loses sales or turns nothing on and concludes the
        // plugin does nothing. Both end in an uninstall.
        Guardify_Onboarding::schedule_redirect();

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
        wp_clear_scheduled_hook('guardify_restore_worker');
        wp_clear_scheduled_hook('guardify_media_worker');
        wp_clear_scheduled_hook('guardify_media_restore_worker');

        // A dump interrupted by deactivation leaves a temp file and a job row behind. The
        // file is the larger problem: it is a full copy of the database sitting in the
        // uploads directory, and nothing will ever come back to remove it.
        $job = get_option('guardify_backup_job', null);
        if (is_array($job) && !empty($job['path']) && file_exists($job['path'])) {
            wp_delete_file($job['path']);
        }
        delete_option('guardify_backup_job');
        delete_transient('guardify_backup_slice_lock');

        // An interrupted restore leaves an archive, an expanded .sql, and staging tables.
        // The files are removed here; the staging tables are deliberately left alone —
        // they hold a database the merchant may still want, and dropping them on
        // deactivation would destroy the only copy of it.
        $restore = get_option('guardify_restore_job', null);
        if (is_array($restore)) {
            foreach (['archive', 'sql'] as $gf_key) {
                if (!empty($restore[$gf_key]) && file_exists($restore[$gf_key])) {
                    wp_delete_file($restore[$gf_key]);
                }
            }
        }
        delete_option('guardify_restore_job');
        delete_option('guardify_domain_change');
        delete_transient('guardify_restore_slice_lock');

        // An interrupted media sync leaves only a job row and possibly one temp file per
        // directory it was writing into. The rows go; the uploaded objects stay, because they
        // are a backup Guardify holds and deactivating a plugin is not a request to delete it.
        // The job's own state must go, or reactivating the plugin resumes a walk against a
        // sync the engine has since expired.
        delete_option('guardify_media_job');
        delete_option('guardify_media_restore');
        delete_transient('guardify_media_slice_lock');

        flush_rewrite_rules();
    }
}
