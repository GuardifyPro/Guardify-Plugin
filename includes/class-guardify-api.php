<?php
defined('ABSPATH') || exit;

/**
 * Guardify API — HMAC-SHA256 signed communication with Guardify Engine.
 *
 * Every request to the engine is signed with the API Key + Secret Key.
 * The secret key is stored encrypted in wp_options.
 */
class Guardify_API {

    private $engine_url;
    private $api_key;
    private $secret_key;

    public function __construct() {
        $this->engine_url = GUARDIFY_ENGINE_URL;
        $this->api_key    = get_option('guardify_api_key', '');
        $this->secret_key = $this->decrypt_secret(get_option('guardify_secret_key_enc', ''));
    }

    /**
     * Check if the plugin is connected (has valid API key).
     */
    public function is_connected() {
        return !empty($this->api_key) && !empty($this->secret_key);
    }

    /**
     * Save API credentials (called during setup / reconnect).
     */
    public function save_credentials($api_key, $secret_key) {
        update_option('guardify_api_key', sanitize_text_field($api_key));
        update_option('guardify_secret_key_enc', $this->encrypt_secret($secret_key));
        $this->api_key    = $api_key;
        $this->secret_key = $secret_key;
    }

    /**
     * Clear stored credentials.
     */
    public function clear_credentials() {
        delete_option('guardify_api_key');
        delete_option('guardify_secret_key_enc');
        $this->api_key    = '';
        $this->secret_key = '';
    }

    /**
     * GET request to engine (HMAC signed).
     */
    public function get($path, $query_params = []) {
        return $this->request('GET', $path, $query_params);
    }

    /**
     * POST request to engine (HMAC signed).
     */
    public function post($path, $body = []) {
        return $this->request('POST', $path, $body);
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

    /**
     * Make an HMAC-signed request to the Guardify Engine.
     */
    private function request($method, $path, $data = []) {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => 'Plugin not connected'];
        }

        $method = strtoupper($method);
        $url    = $this->engine_url . $path;
        $signed_path = $path;

        if ($method === 'GET' && !empty($data)) {
            $query = http_build_query($data);
            $url .= '?' . $query;
            $signed_path = $path . '?' . $query;
            $body_str = '';
        } else {
            $body_str = wp_json_encode($data);
        }

        $headers = $this->sign_request($method, $signed_path, $body_str);

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => $headers,
        ];

        if ($method === 'POST') {
            $args['body'] = $body_str;
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

    // ─── HMAC Signing ────────────────────────────────────────────────────

    /**
     * Generate HMAC-SHA256 signed headers.
     *
     * String-to-Sign: METHOD\nPATH\nTIMESTAMP\nSHA256(BODY)
     */
    private function sign_request($method, $path, $body_str) {
        $timestamp = (string) time();
        $body_hash = hash('sha256', (string) $body_str);

        $string_to_sign = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $body_hash;
        $signature      = hash_hmac('sha256', $string_to_sign, $this->secret_key);

        return [
            'X-GF-Key'       => $this->api_key,
            'X-GF-Timestamp' => $timestamp,
            'X-GF-Signature' => $signature,
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
        ];
    }

    // ─── Secret Key Encryption ───────────────────────────────────────────

    /**
     * Encrypt the secret key for storage in wp_options.
     */
    private function encrypt_secret($plain) {
        if (empty($plain)) {
            return '';
        }

        $key    = $this->get_encryption_key();
        $iv     = openssl_random_pseudo_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    /**
     * Decrypt the secret key from wp_options.
     */
    private function decrypt_secret($encrypted) {
        if (empty($encrypted)) {
            return '';
        }

        $key  = $this->get_encryption_key();
        $data = base64_decode($encrypted, true);

        if ($data === false || strlen($data) < 17) {
            return '';
        }

        $iv     = substr($data, 0, 16);
        $cipher = substr($data, 16);
        $plain  = openssl_decrypt($cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $plain !== false ? $plain : '';
    }

    /**
     * Derive encryption key from WordPress salts.
     */
    private function get_encryption_key() {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'guardify-default-key';
        return hash('sha256', $salt . 'guardify-secret-enc', true);
    }
}
