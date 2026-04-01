<?php
defined('ABSPATH') || exit;

/**
 * Guardify Backup – Automated WordPress database backup & restore.
 *
 * - Exports WP database tables to a compressed .sql.gz file
 * - Uploads to Guardify Engine (stored in R2)
 * - Supports scheduled (WP Cron) and manual backups
 * - Restore by downloading and importing a selected backup
 */
class Guardify_Backup {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // WP Cron hook
        add_action('guardify_scheduled_backup', [$this, 'run_scheduled_backup']);

        // Pending backup check cron
        add_action('guardify_check_pending_backup', [$this, 'process_pending_requests']);

        // Custom cron schedule
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // Schedule pending check if not scheduled
        if (!wp_next_scheduled('guardify_check_pending_backup')) {
            wp_schedule_event(time(), 'guardify_every_5min', 'guardify_check_pending_backup');
        }

        // Also check on admin init (throttled)
        add_action('admin_init', [$this, 'maybe_check_pending']);

        // AJAX handlers
        add_action('wp_ajax_guardify_backup_now', [$this, 'ajax_backup_now']);
        add_action('wp_ajax_guardify_backup_list', [$this, 'ajax_backup_list']);
        add_action('wp_ajax_guardify_backup_restore', [$this, 'ajax_backup_restore']);
        add_action('wp_ajax_guardify_backup_save_schedule', [$this, 'ajax_save_schedule']);
    }

    /**
     * Register custom cron schedules.
     */
    public function add_cron_schedules($schedules) {
        $schedules['guardify_every_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'প্রতি ৫ মিনিটে',
        ];
        $schedules['guardify_every_6h'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'প্রতি ৬ ঘণ্টায়',
        ];
        $schedules['guardify_every_12h'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'প্রতি ১২ ঘণ্টায়',
        ];
        return $schedules;
    }

    /**
     * Schedule or reschedule the backup cron.
     */
    public function schedule_backup() {
        // Clear existing
        wp_clear_scheduled_hook('guardify_scheduled_backup');

        $enabled = get_option('guardify_backup_enabled', 'no');
        if ($enabled !== 'yes') {
            return;
        }

        $frequency = get_option('guardify_backup_frequency', 'daily');
        $time_str  = get_option('guardify_backup_time', '05:00');
        $timezone  = get_option('guardify_backup_timezone', 'Asia/Dhaka');

        // Calculate next run time in the target timezone
        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Asia/Dhaka');
        }

        $now  = new DateTime('now', $tz);
        $next = new DateTime($now->format('Y-m-d') . ' ' . $time_str, $tz);

        // If the time has already passed today, schedule for tomorrow
        if ($next <= $now) {
            $next->modify('+1 day');
        }

        $timestamp = $next->getTimestamp();

        // Map frequency to WP cron recurrence
        $recurrence_map = [
            'every_6h'  => 'guardify_every_6h',
            'every_12h' => 'guardify_every_12h',
            'daily'     => 'daily',
            'weekly'    => 'weekly',
        ];
        $recurrence = isset($recurrence_map[$frequency]) ? $recurrence_map[$frequency] : 'daily';

        wp_schedule_event($timestamp, $recurrence, 'guardify_scheduled_backup');
    }

    /**
     * WP Cron callback — run the backup.
     */
    public function run_scheduled_backup() {
        // Prevent overlapping backups
        if (get_transient('guardify_backup_running')) {
            return;
        }
        set_transient('guardify_backup_running', true, 10 * MINUTE_IN_SECONDS);

        $result = $this->create_backup('স্বয়ংক্রিয় ব্যাকআপ');

        delete_transient('guardify_backup_running');

        if (is_wp_error($result)) {
            error_log('Guardify Backup Error: ' . $result->get_error_message());
        }
    }

    /**
     * Check for pending backup requests on admin page load (throttled to once per 5 min).
     */
    public function maybe_check_pending() {
        if (get_transient('guardify_pending_checked')) {
            return;
        }
        set_transient('guardify_pending_checked', true, 5 * MINUTE_IN_SECONDS);
        $this->process_pending_requests();
    }

    /**
     * Poll the engine for pending backup requests and process them.
     */
    public function process_pending_requests() {
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        $result = $api->get('/api/v1/backup/pending');
        if (empty($result['data']['pending'])) {
            return;
        }

        $pending = $result['data']['pending'];
        foreach ($pending as $request) {
            $request_id = isset($request['id']) ? sanitize_text_field($request['id']) : '';
            $note       = isset($request['note']) ? sanitize_text_field($request['note']) : 'রিমোট ব্যাকআপ';

            if (empty($request_id)) {
                continue;
            }

            // Prevent overlapping
            if (get_transient('guardify_backup_running')) {
                break;
            }
            set_transient('guardify_backup_running', true, 10 * MINUTE_IN_SECONDS);

            $backup_result = $this->create_backup($note);

            delete_transient('guardify_backup_running');

            // Acknowledge the request regardless of result
            $api->post('/api/v1/backup/ack', ['id' => $request_id]);

            if (is_wp_error($backup_result)) {
                error_log('Guardify Remote Backup Error: ' . $backup_result->get_error_message());
            }
        }
    }

    /**
     * Create a database backup and upload to the engine.
     *
     * @param string $note Optional note for the backup.
     * @return array|WP_Error
     */
    public function create_backup($note = '') {
        global $wpdb;

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।');
        }

        // Get all tables for this WP installation
        $tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
        if (empty($tables)) {
            return new WP_Error('no_tables', 'কোনো ডাটাবেইজ টেবিল পাওয়া যায়নি।');
        }

        // Create temp file for the dump
        $temp_file = wp_tempnam('guardify_backup_');
        if (!$temp_file) {
            return new WP_Error('temp_file', 'টেম্প ফাইল তৈরি করা যায়নি।');
        }

        $gz = gzopen($temp_file, 'wb9');
        if (!$gz) {
            wp_delete_file($temp_file);
            return new WP_Error('gz_open', 'কম্প্রেশন শুরু করা যায়নি।');
        }

        // Write header
        $header = "-- Guardify Pro Database Backup\n";
        $header .= "-- Date: " . gmdate('Y-m-d H:i:s') . " UTC\n";
        $header .= "-- Site: " . site_url() . "\n";
        $header .= "-- WordPress: " . get_bloginfo('version') . "\n";
        $header .= "-- Tables: " . count($tables) . "\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET foreign_key_checks = 0;\n\n";
        gzwrite($gz, $header);

        foreach ($tables as $table) {
            // Table structure
            $create = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_N);
            if ($create) {
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n");
                gzwrite($gz, $create[1] . ";\n\n");
            }

            // Table data — export in chunks to avoid memory issues
            $offset = 0;
            $chunk  = 500;
            do {
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $chunk, $offset),
                    ARRAY_A
                );

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $values = array_map(function ($v) use ($wpdb) {
                        if (is_null($v)) {
                            return 'NULL';
                        }
                        return "'" . esc_sql($v) . "'";
                    }, $row);

                    $columns = array_map(function ($c) {
                        return '`' . $c . '`';
                    }, array_keys($row));

                    $sql = "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES (" . implode(',', $values) . ");\n";
                    gzwrite($gz, $sql);
                }

                $offset += $chunk;
            } while (count($rows) === $chunk);

            gzwrite($gz, "\n");
        }

        gzwrite($gz, "SET foreign_key_checks = 1;\n");
        gzclose($gz);

        // Upload via multipart POST to engine
        $file_size = filesize($temp_file);
        $result = $this->upload_to_engine($temp_file, $note);

        // Cleanup temp file
        wp_delete_file($temp_file);

        return $result;
    }

    /**
     * Upload a backup file to the engine via multipart form POST.
     */
    private function upload_to_engine($file_path, $note = '') {
        $api_key = get_option('guardify_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_key', 'API কী পাওয়া যায়নি।');
        }

        $url = GUARDIFY_ENGINE_URL . '/api/v1/backup/upload';

        // Use cURL for multipart file upload
        $boundary = wp_generate_password(24, false);
        $body     = '';

        // File field
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"backup.sql.gz\"\r\n";
        $body .= "Content-Type: application/gzip\r\n\r\n";
        $body .= file_get_contents($file_path); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $body .= "\r\n";

        // Note field
        if (!empty($note)) {
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Disposition: form-data; name=\"note\"\r\n\r\n";
            $body .= $note . "\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        $response = wp_remote_post($url, [
            'timeout' => 120,
            'headers' => [
                'X-GF-Key'      => $api_key,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 201 && !empty($data['data'])) {
            return $data['data'];
        }

        $error_msg = isset($data['error']['message']) ? $data['error']['message'] : 'আপলোড ব্যর্থ হয়েছে (HTTP ' . $code . ')';
        return new WP_Error('upload_failed', $error_msg);
    }

    /**
     * Restore a database backup.
     *
     * @param string $backup_id The backup UUID.
     * @return true|WP_Error
     */
    public function restore_backup($backup_id) {
        $api_key = get_option('guardify_api_key', '');
        if (empty($api_key)) {
            return new WP_Error('no_key', 'API কী পাওয়া যায়নি।');
        }

        // Download the backup file from engine
        $url = GUARDIFY_ENGINE_URL . '/api/v1/backup/download?id=' . rawurlencode($backup_id);

        $temp_file = wp_tempnam('guardify_restore_');

        $response = wp_remote_get($url, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $temp_file,
            'headers'  => [
                'X-GF-Key' => $api_key,
                'Accept'   => 'application/gzip',
            ],
        ]);

        if (is_wp_error($response)) {
            wp_delete_file($temp_file);
            return new WP_Error('download_failed', 'ব্যাকআপ ডাউনলোড ব্যর্থ: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_delete_file($temp_file);
            return new WP_Error('download_failed', 'ব্যাকআপ ডাউনলোড ব্যর্থ (HTTP ' . $code . ')');
        }

        // Import the SQL dump
        $result = $this->import_sql_gz($temp_file);
        wp_delete_file($temp_file);

        return $result;
    }

    /**
     * Import a gzipped SQL file into the WordPress database.
     */
    private function import_sql_gz($file_path) {
        global $wpdb;

        $gz = gzopen($file_path, 'rb');
        if (!$gz) {
            return new WP_Error('gz_open', 'ব্যাকআপ ফাইল খোলা যায়নি।');
        }

        $buffer    = '';
        $errors    = [];
        $executed  = 0;

        while (!gzeof($gz)) {
            $line = gzgets($gz, 65536);
            if ($line === false) {
                break;
            }

            // Skip comments and empty lines
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                continue;
            }

            $buffer .= $line;

            // Execute when we hit a semicolon at end of line
            if (substr(rtrim($buffer), -1) === ';') {
                $sql = trim($buffer);
                $buffer = '';

                if (empty($sql)) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importing raw SQL backup
                $result = $wpdb->query($sql);
                if ($result === false) {
                    $errors[] = $wpdb->last_error;
                    if (count($errors) > 10) {
                        gzclose($gz);
                        return new WP_Error('import_errors', 'অনেক ত্রুটি হয়েছে। প্রথম: ' . $errors[0]);
                    }
                } else {
                    $executed++;
                }
            }
        }

        gzclose($gz);

        if (!empty($errors) && $executed === 0) {
            return new WP_Error('import_failed', 'ইম্পোর্ট সম্পূর্ণ ব্যর্থ: ' . $errors[0]);
        }

        return true;
    }

    // ─── AJAX Handlers ───────────────────────────────────────────────────

    /**
     * AJAX: Run a manual backup now.
     */
    public function ajax_backup_now() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        // Prevent overlapping
        if (get_transient('guardify_backup_running')) {
            wp_send_json_error('আরেকটি ব্যাকআপ চলছে। অনুগ্রহ করে অপেক্ষা করুন।');
        }
        set_transient('guardify_backup_running', true, 10 * MINUTE_IN_SECONDS);

        $result = $this->create_backup('ম্যানুয়াল ব্যাকআপ');

        delete_transient('guardify_backup_running');

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'message' => 'ব্যাকআপ সফলভাবে সম্পন্ন হয়েছে।',
            'backup'  => $result,
        ]);
    }

    /**
     * AJAX: List backups for this site.
     */
    public function ajax_backup_list() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $api    = new Guardify_API();
        $result = $api->get('/api/v1/backup/list');

        if (!empty($result['data'])) {
            wp_send_json_success($result['data']);
        } elseif (isset($result['backups'])) {
            wp_send_json_success($result);
        }

        wp_send_json_success(['backups' => [], 'count' => 0]);
    }

    /**
     * AJAX: Restore from a specific backup.
     */
    public function ajax_backup_restore() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        if (empty($backup_id) || !preg_match('/^[a-f0-9\-]{36}$/i', $backup_id)) {
            wp_send_json_error('সঠিক ব্যাকআপ নির্বাচন করুন।');
        }

        // Safety: create a pre-restore backup first
        $pre_result = $this->create_backup('রিস্টোরের আগে স্বয়ংক্রিয় ব্যাকআপ');
        if (is_wp_error($pre_result)) {
            // Log but don't block restore
            error_log('Guardify: Pre-restore backup failed: ' . $pre_result->get_error_message());
        }

        $result = $this->restore_backup($backup_id);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success(['message' => 'ডাটাবেইজ সফলভাবে রিস্টোর হয়েছে।']);
    }

    /**
     * AJAX: Save backup schedule settings.
     */
    public function ajax_save_schedule() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $enabled   = isset($_POST['guardify_backup_enabled']) && $_POST['guardify_backup_enabled'] === 'yes' ? 'yes' : 'no';
        $frequency = isset($_POST['guardify_backup_frequency']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_frequency'])) : 'daily';
        $time      = isset($_POST['guardify_backup_time']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_time'])) : '05:00';
        $timezone  = isset($_POST['guardify_backup_timezone']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_timezone'])) : 'Asia/Dhaka';

        // Validate
        $valid_freqs = ['every_6h', 'every_12h', 'daily', 'weekly'];
        if (!in_array($frequency, $valid_freqs, true)) {
            $frequency = 'daily';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '05:00';
        }
        try {
            new DateTimeZone($timezone);
        } catch (Exception $e) {
            $timezone = 'Asia/Dhaka';
        }

        update_option('guardify_backup_enabled', $enabled);
        update_option('guardify_backup_frequency', $frequency);
        update_option('guardify_backup_time', $time);
        update_option('guardify_backup_timezone', $timezone);

        // Reschedule cron
        $this->schedule_backup();

        wp_send_json_success(['message' => 'ব্যাকআপ শিডিউল সেভ হয়েছে।']);
    }

    /**
     * Get schedule info for display.
     */
    public function get_schedule_info() {
        $enabled   = get_option('guardify_backup_enabled', 'no');
        $frequency = get_option('guardify_backup_frequency', 'daily');
        $time      = get_option('guardify_backup_time', '05:00');
        $timezone  = get_option('guardify_backup_timezone', 'Asia/Dhaka');

        $next_run = wp_next_scheduled('guardify_scheduled_backup');

        return [
            'enabled'   => $enabled,
            'frequency' => $frequency,
            'time'      => $time,
            'timezone'  => $timezone,
            'next_run'  => $next_run ? gmdate('Y-m-d H:i:s', $next_run) . ' UTC' : null,
        ];
    }
}
