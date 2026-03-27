<?php
/**
 * Guardify Pro — Uninstall
 *
 * Fired when the plugin is deleted via the Plugins admin page.
 * Removes all stored options.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

// Remove all options
delete_option('guardify_api_key');
delete_option('guardify_secret_key_enc');
delete_option('guardify_smart_filter_enabled');
delete_option('guardify_smart_filter_threshold');
delete_option('guardify_smart_filter_action');
delete_option('guardify_smart_filter_skip_new');
delete_option('guardify_otp_enabled');
delete_option('guardify_vpn_block_enabled');
delete_option('guardify_repeat_blocker_enabled');
delete_option('guardify_repeat_blocker_hours');
delete_option('guardify_incomplete_orders_enabled');
delete_option('guardify_sms_notifications_enabled');
delete_option('guardify_notification_statuses');
delete_option('guardify_notification_templates');
delete_option('guardify_phone_history_enabled');
delete_option('guardify_report_column_enabled');
delete_option('guardify_fraud_detection_enabled');
delete_option('guardify_fraud_auto_block_dp');

// Drop custom tables
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}guardify_incomplete_orders");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}guardify_fraud_tracking");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}guardify_blocks");

// Clear scheduled events
wp_clear_scheduled_hook('guardify_cleanup_incomplete');
