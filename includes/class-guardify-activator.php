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

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }
}
