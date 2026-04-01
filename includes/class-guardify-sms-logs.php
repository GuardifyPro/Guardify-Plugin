<?php
defined('ABSPATH') || exit;

/**
 * Guardify SMS Logs — Fetch and display SMS sending history.
 */
class Guardify_SMS_Logs {

    private static $instance = null;
    private static $cached_logs = null;
    private static $cached_total = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Fetch SMS logs via the Guardify Engine API.
     */
    public function fetch_logs($page = 1, $per_page = 20) {
        $api = new Guardify_API();

        if (!$api->is_connected()) {
            return [
                'success' => false,
                'message' => 'Plugin connected নয়। সেটিংস থেকে সংযুক্ত করুন।',
            ];
        }

        // Use static cache within the same request
        if (self::$cached_logs === null) {
            $result = $api->get('/api/v1/sms/logs');

            if (!empty($result['error'])) {
                return [
                    'success' => false,
                    'message' => $result['error'],
                ];
            }

            $logs = [];
            if (isset($result['data']['logs']) && is_array($result['data']['logs'])) {
                $logs = $result['data']['logs'];
            } elseif (isset($result['logs']) && is_array($result['logs'])) {
                $logs = $result['logs'];
            }

            self::$cached_logs  = $logs;
            self::$cached_total = isset($result['data']['total_logs'])
                ? (int) $result['data']['total_logs']
                : (isset($result['total_logs']) ? (int) $result['total_logs'] : count($logs));
        }

        // Manual pagination
        $start = ($page - 1) * $per_page;
        $slice = array_slice(self::$cached_logs, $start, $per_page);

        return [
            'success' => true,
            'logs'    => $slice,
            'total'   => self::$cached_total,
        ];
    }

    /**
     * Convert a timestamp to Asia/Dhaka timezone.
     */
    public static function to_dhaka($timestamp) {
        if (empty($timestamp)) {
            return new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        }
        try {
            $dt = new DateTime($timestamp, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
            return $dt;
        } catch (Exception $e) {
            return new DateTime('now', new DateTimeZone('Asia/Dhaka'));
        }
    }
}
