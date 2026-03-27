<?php
/**
 * Guardify Pro — Uninstall
 *
 * Fired when the plugin is deleted via the Plugins admin page.
 * Removes all stored options.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('guardify_api_key');
delete_option('guardify_secret_key_enc');
