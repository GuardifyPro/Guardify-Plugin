<?php
defined('ABSPATH') || exit;

class Guardify_Activator {

    /**
     * Schema revision. Bump this whenever a create_table() definition changes.
     *
     * Without it, dbDelta only ever ran on activation — so a column or index added in a
     * release reached new installs and nothing else. Every existing shop kept the old schema
     * while running code that assumed the new one, and the failure is not a clean error: a
     * missing index is a query that silently gets slower as the table grows, and a missing
     * column is a write that fails on one site and works on the next.
     */
    const DB_VERSION = 2;

    const DB_VERSION_OPTION = 'guardify_db_version';

    public static function activate() {
        // Set default options
        if (false === get_option('guardify_api_key')) {
            add_option('guardify_api_key', '');
        }
        if (false === get_option('guardify_secret_key_enc')) {
            add_option('guardify_secret_key_enc', '');
        }

        // Create custom DB tables
        self::install_tables();

        // Open the setup wizard on the next admin page load. A merchant who lands on an
        // 819-line settings screen with a dozen toggles, several of which refuse orders,
        // either turns everything on and loses sales or turns nothing on and concludes the
        // plugin does nothing. Both end in an uninstall.
        Guardify_Onboarding::schedule_redirect();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Apply the table definitions and record the revision they came from.
     */
    public static function install_tables() {
        Guardify_Incomplete_Orders::create_table();
        Guardify_Fraud_Detection::create_tables();

        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Bring an already-installed site's schema up to date.
     *
     * Hooked on admin_init rather than plugins_loaded on purpose. dbDelta reads the whole
     * table definition and can issue ALTERs, which is not something to do on a customer's
     * page load — and an upgrade a merchant never triggers because they never open wp-admin
     * is not a situation that arises.
     *
     * The common path is one comparison against an autoloaded option, so this costs nothing
     * on the hundreds of admin page loads between releases.
     */
    public static function maybe_upgrade() {
        if ((int) get_option(self::DB_VERSION_OPTION, 0) === self::DB_VERSION) {
            return;
        }

        // A second admin request arriving while the first is still running dbDelta would run
        // it again concurrently. Harmless in principle — dbDelta is idempotent — but two
        // simultaneous ALTERs on the same table is a lock nobody needs.
        if (get_transient('guardify_db_upgrading')) {
            return;
        }
        set_transient('guardify_db_upgrading', 1, 2 * MINUTE_IN_SECONDS);

        self::install_tables();
        self::clean_legacy_options();

        delete_transient('guardify_db_upgrading');
    }

    /**
     * Remove options left behind by earlier versions.
     *
     * This used to be a delete_option() call on the `init` hook, which meant it ran on every
     * single request the site ever served — and delete_option() does not consult the options
     * cache before looking, so it was an uncached SELECT on every page load, for the life of
     * the install, to re-discover that a row deleted long ago is still deleted.
     *
     * A one-time cleanup belongs on the one-time path.
     */
    private static function clean_legacy_options() {
        $legacy = [
            'guardify_phone_sync_offset',
        ];

        foreach ($legacy as $option) {
            delete_option($option);
        }
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
        wp_clear_scheduled_hook('guardify_site_manager_poll');

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
