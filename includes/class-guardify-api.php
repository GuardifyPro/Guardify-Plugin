<?php
defined('ABSPATH') || exit;

/**
 * Guardify API — Simple key-based communication with Guardify Engine.
 *
 * Every request includes the API key in the X-GF-Key header.
 */
class Guardify_API {

    private $engine_url;
    private $api_key;

    public function __construct() {
        $this->engine_url = GUARDIFY_ENGINE_URL;
        $this->api_key    = get_option('guardify_api_key', '');
    }

    /**
     * Check if the plugin is connected (has API key).
     */
    public function is_connected() {
        return !empty($this->api_key);
    }

    /**
     * Save API key.
     */
    public function save_credentials($api_key) {
        update_option('guardify_api_key', sanitize_text_field($api_key));
        $this->api_key = $api_key;
    }

    /**
     * Clear stored credentials.
     */
    public function clear_credentials() {
        delete_option('guardify_api_key');
        // Clean up legacy secret if it exists
        delete_option('guardify_secret_key_enc');
        $this->api_key = '';
    }

    /**
     * GET request to engine.
     */
    public function get($path, $query_params = []) {
        return $this->request('GET', $path, $query_params);
    }

    /**
     * POST request to engine.
     */
    public function post($path, $body = []) {
        return $this->request('POST', $path, $body);
    }

    /**
     * Non-blocking POST — fire-and-forget.
     */
    public function post_async($path, $body = []) {
        return $this->request('POST', $path, $body, false);
    }

    /**
     * Check API key status.
     */
    public function check_status() {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => 'Not connected'];
        }
        return $this->get('/api/v1/auth/status');
    }

    /**
     * Validate API key (first connection).
     */
    public function check_key() {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => 'No API key configured'];
        }
        return $this->get('/api/v1/auth/check');
    }

    // ─── Core Request Method ─────────────────────────────────────────────

    private function request($method, $path, $data = [], $blocking = true) {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => 'Plugin not connected'];
        }

        $method = strtoupper($method);
        $url    = $this->engine_url . $path;

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $args = [
            'method'   => $method,
            'timeout'  => $blocking ? 30 : 1,
            'blocking' => $blocking,
            'headers'  => [
                'X-GF-Key'      => $this->api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($method === 'POST') {
            $args['body'] = wp_json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error'   => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && is_array($body)) {
            return $body;
        }

        return [
            'success' => false,
            'error'   => isset($body['error']['message']) ? $body['error']['message'] : 'Request failed (HTTP ' . $code . ')',
        ];
    }
}
