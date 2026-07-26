<?php
defined('ABSPATH') || exit;

/**
 * Guardify_API — signed communication with the Guardify engine.
 *
 * Every request carries an HMAC-SHA256 signature over the method, path, timestamp,
 * nonce and a digest of the body (see Guardify_Signer). The signing secret is stored
 * encrypted at rest (see Guardify_Crypto).
 *
 * Keys issued before signing existed authenticate on the bearer key alone. The first
 * time such a key is used, this class transparently calls /auth/upgrade to collect
 * its signing secret and switches to signed requests permanently. Merchants never
 * see this happen and never have to re-enter anything.
 */
class Guardify_API {

    const OPT_KEY        = 'guardify_api_key';
    const OPT_SECRET     = 'guardify_signing_secret';
    const OPT_AUTH_MODE  = 'guardify_auth_mode';
    const OPT_LAST_ERROR = 'guardify_last_api_error';
    /** Set when the engine holds a signing secret this install can no longer produce. */
    const OPT_SECRET_LOST = 'guardify_signing_secret_lost';
    /** Consecutive rejected signatures, for the settings screen. */
    const TRANSIENT_SIG_FAILURES = 'guardify_signature_failures';

    /** Requests that must never be retried because they are not idempotent. */
    const NON_IDEMPOTENT = [
        '/api/v1/courier/send',
        '/api/v1/sms/send',
        '/api/v1/otp/send',
        // Single-use: the signing secret is issued exactly once. A retry after a first attempt
        // that actually succeeded but timed out would spend the only chance to collect it.
        '/api/v1/auth/upgrade',
    ];

    private $engine_url;
    private $api_key;
    private $secret;

    public function __construct() {
        $this->engine_url = untrailingslashit(GUARDIFY_ENGINE_URL);
        $this->api_key    = (string) get_option(self::OPT_KEY, '');
        $this->secret     = Guardify_Crypto::decrypt((string) get_option(self::OPT_SECRET, ''));
    }

    public function is_connected() {
        return $this->api_key !== '';
    }

    /**
     * Whether this key authenticates with signed requests.
     */
    public function is_signed() {
        return $this->secret !== '' && get_option(self::OPT_AUTH_MODE, 'legacy') === 'hmac';
    }

    /**
     * Save a key entered by the merchant. Clears any secret belonging to a previous
     * key so a stale secret can never be paired with a new key.
     *
     * @param string $api_key
     */
    public function save_credentials($api_key) {
        $api_key = sanitize_text_field($api_key);

        update_option(self::OPT_KEY, $api_key, false);
        delete_option(self::OPT_SECRET);
        update_option(self::OPT_AUTH_MODE, 'legacy', false);
        delete_transient('guardify_upgrade_attempted');

        $this->api_key = $api_key;
        $this->secret  = '';
    }

    /**
     * Store the signing secret handed out by /auth/upgrade or shown at key creation.
     *
     * @param string $secret 64 hex characters.
     * @return bool
     */
    public function save_signing_secret($secret) {
        $secret = trim((string) $secret);
        if (!preg_match('/^[a-f0-9]{64}$/i', $secret)) {
            return false;
        }

        update_option(self::OPT_SECRET, Guardify_Crypto::encrypt($secret), false);
        update_option(self::OPT_AUTH_MODE, 'hmac', false);

        $this->secret = $secret;
        return true;
    }

    /**
     * Whether the engine expects signed requests but this install cannot produce them.
     *
     * Reached when the signing secret is gone and cannot be reissued — most often after
     * rotating the wp-config salts (routine hardening advice, and mandatory after a
     * compromise), which changes the key that Guardify_Crypto derives and makes the stored
     * ciphertext undecryptable. The merchant needs a new API key, and needs to be told so
     * rather than left with silently failing requests.
     */
    public function secret_lost() {
        return (bool) get_option(self::OPT_SECRET_LOST, false)
            || (get_option(self::OPT_AUTH_MODE, 'legacy') === 'hmac' && $this->secret === '');
    }

    /**
     * Consecutive rejected signatures, for display in settings.
     */
    public function signature_failures() {
        return (int) get_transient(self::TRANSIENT_SIG_FAILURES);
    }

    public function clear_credentials() {
        delete_option(self::OPT_KEY);
        delete_option(self::OPT_SECRET_LOST);
        delete_transient(self::TRANSIENT_SIG_FAILURES);
        delete_option(self::OPT_SECRET);
        delete_option(self::OPT_AUTH_MODE);
        delete_transient(self::OPT_LAST_ERROR);
        delete_option(self::OPT_LAST_ERROR); // pre-0.5 installs stored this as an option
        delete_option('guardify_secret_key_enc'); // removed in 0.4.x
        delete_transient('guardify_upgrade_attempted');

        $this->api_key = '';
        $this->secret  = '';
    }

    /**
     * Exchange a legacy bearer key for a signing secret.
     *
     * Attempted at most once per hour so a persistently failing upgrade cannot turn
     * into a request storm against the engine.
     *
     * @return bool True when the key is now in signed mode.
     */
    public function ensure_signed() {
        if ($this->is_signed() || !$this->is_connected()) {
            return $this->is_signed();
        }
        if (get_transient('guardify_upgrade_attempted')) {
            return false;
        }
        set_transient('guardify_upgrade_attempted', 1, HOUR_IN_SECONDS);

        $result = $this->request('POST', '/api/v1/auth/upgrade', [], true, false);

        $secret = '';
        if (isset($result['signing_secret'])) {
            $secret = $result['signing_secret'];
        } elseif (isset($result['data']['signing_secret'])) {
            $secret = $result['data']['signing_secret'];
        }

        if ($secret !== '' && $this->save_signing_secret($secret)) {
            delete_transient('guardify_upgrade_attempted');
            return true;
        }

        return false;
    }

    /**
     * @param string $path
     * @param array  $query_params
     * @param array  $opts {timeout?: float, retries?: int}
     */
    public function get($path, $query_params = [], $opts = []) {
        return $this->request('GET', $path, $query_params, true, true, $opts);
    }

    /**
     * @param array $opts {timeout?: float, retries?: int}
     */
    public function post($path, $body = [], $opts = []) {
        return $this->request('POST', $path, $body, true, true, $opts);
    }

    /**
     * Fire-and-forget POST. Used where the response is not needed and the merchant's
     * checkout must not wait on the network.
     */
    public function post_async($path, $body = []) {
        return $this->request('POST', $path, $body, false);
    }

    /**
     * Request options for anything called during checkout.
     *
     * Checkout is the one place where a slow response costs the merchant money rather
     * than convenience. `woocommerce_checkout_process` runs *before* the order is created,
     * so if the request outlives PHP's max_execution_time — commonly 30s on the shared
     * hosting most Bangladeshi shops use — the customer gets a fatal error, no order row
     * exists, and the merchant never learns the sale was attempted.
     *
     * Two calls can be registered on that hook (risk assessment and the VPN check), so the
     * per-call budget has to assume it is not alone. At 2.5 seconds with no retry, the
     * worst case across both is 5 seconds: slow, survivable, and it always ends in the
     * fail-open path rather than a white screen.
     *
     * Retries are explicitly disabled here. Elsewhere they buy reliability; here they
     * multiply the one number that must stay small.
     *
     * @return array
     */
    public static function checkout_opts() {
        return ['timeout' => 2.5, 'retries' => 1];
    }

    /**
     * Authentication headers for a request this class cannot make itself.
     *
     * Streaming downloads need wp_remote_get's `stream` and `filename` options, which the
     * shared request path does not carry. Rather than letting those callers hand-roll an
     * `X-GF-Key` header — which is unsigned, and therefore rejected outright once a key is in
     * hmac mode — they take the real headers from here.
     *
     * @param string $method
     * @param string $path_with_query Path including any query string, exactly as it will be sent.
     * @param string $body            Raw request body, '' for GET.
     * @return array<string,string>
     */
    public function signed_headers($method, $path_with_query, $body = '') {
        if ($this->is_connected() && !$this->is_signed()) {
            $this->ensure_signed();
        }

        return Guardify_Signer::headers($this->api_key, $this->secret, $method, $path_with_query, $body);
    }

    /**
     * The engine base URL, for callers building their own request.
     */
    public function engine_url() {
        return $this->engine_url;
    }

    public function check_status() {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => __('Not connected', 'guardify-pro')];
        }
        return $this->get('/api/v1/auth/status');
    }

    public function check_key() {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => __('No API key configured', 'guardify-pro')];
        }
        return $this->get('/api/v1/auth/check');
    }

    /**
     * The last transport or authentication error, for display in settings.
     *
     * @return array{code:string,message:string,time:int}|null
     */
    public function last_error() {
        $err = get_transient(self::OPT_LAST_ERROR);
        return is_array($err) ? $err : null;
    }

    // ─── Core request ────────────────────────────────────────────────────────

    /**
     * @param string $method
     * @param string $path
     * @param array  $data
     * @param bool   $blocking      False for fire-and-forget.
     * @param bool   $allow_upgrade Set false to prevent recursion from ensure_signed().
     * @param array  $opts          {timeout?: float, retries?: int} — see checkout_opts().
     * @return array
     */
    private function request($method, $path, $data = [], $blocking = true, $allow_upgrade = true, $opts = []) {
        if (!$this->is_connected()) {
            return ['success' => false, 'error' => __('Plugin not connected', 'guardify-pro')];
        }

        $timeout    = isset($opts['timeout']) ? (float) $opts['timeout'] : 20.0;
        $max_tries  = isset($opts['retries']) ? max(1, (int) $opts['retries']) : null;

        // Opportunistically move legacy keys onto signed requests.
        //
        // Skipped when the caller asked for a tight budget: the upgrade is an extra
        // round trip, and spending a checkout's entire allowance on it would stall the
        // very request the customer is waiting on. The next admin-side call does it.
        if ($allow_upgrade && !$this->is_signed() && $max_tries !== 1) {
            $this->ensure_signed();
        }

        $method = strtoupper($method);
        $body   = '';

        $path_with_query = $path;
        if ($method === 'GET' && !empty($data)) {
            $path_with_query = $path . '?' . http_build_query($data);
        } elseif ($method !== 'GET') {
            $body = wp_json_encode($data);
            if ($body === false) {
                return ['success' => false, 'error' => __('Could not encode request payload', 'guardify-pro')];
            }
        }

        $base_headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        $args = [
            'method'   => $method,
            'timeout'  => $blocking ? $timeout : 1,
            'blocking' => (bool) $blocking,
            'headers'  => array_merge(
                Guardify_Signer::headers($this->api_key, $this->secret, $method, $path_with_query, $body),
                $base_headers
            ),
            // The engine is a known first-party host; never follow it elsewhere.
            'redirection' => 0,
            'sslverify'   => true,
            'user-agent'  => 'Guardify-Pro/' . GUARDIFY_VERSION . '; ' . home_url('/'),
        ];

        if ($method !== 'GET') {
            $args['body'] = $body;
        }

        // An explicit retry budget always wins. Retries improve reliability for background
        // work, but on a request the customer is waiting for they only multiply the wait.
        if ($max_tries !== null) {
            $attempts = $max_tries;
        } else {
            $attempts = $this->is_retryable($method, $path) ? 3 : 1;
        }
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($attempt > 1) {
                // Re-sign every attempt: the timestamp and nonce must be fresh,
                // otherwise a retry looks like a replay and the engine rejects it.
                $args['headers'] = array_merge(
                    Guardify_Signer::headers($this->api_key, $this->secret, $method, $path_with_query, $body),
                    $base_headers
                );
                usleep(200000 * (int) pow(2, $attempt - 2)); // 200ms, then 400ms
            }

            $response = wp_remote_request($this->engine_url . $path_with_query, $args);

            if (!$blocking) {
                return ['success' => true];
            }
            if (is_wp_error($response)) {
                continue; // transport failure: worth another try
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 500 && $code !== 429) {
                break; // a definite answer; retrying would not change it
            }
        }

        if (is_wp_error($response)) {
            return $this->fail('TRANSPORT', $response->get_error_message());
        }

        $code    = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 200 && $code < 300 && is_array($decoded)) {
            delete_transient(self::OPT_LAST_ERROR);
            delete_transient(self::TRANSIENT_SIG_FAILURES);
            // Unwrap the engine's {success, data} envelope so callers see the payload.
            if (array_key_exists('success', $decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                return $decoded['data'];
            }
            return $decoded;
        }

        $code_str = isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : 'HTTP_' . $code;
        $message  = isset($decoded['error']['message'])
            ? (string) $decoded['error']['message']
            : sprintf(
                /* translators: %d: HTTP status code */
                __('Request failed (HTTP %d)', 'guardify-pro'),
                $code
            );

        // A rejected signature must not destroy the secret.
        //
        // Deleting it looks like self-healing but is a one-way trip: the engine keeps the key
        // in hmac mode, and the only route to a replacement secret — /auth/upgrade — refuses
        // unsigned calls and is single-use anyway. So one transient rejection, from a WAF
        // rewriting the request line or a proxy re-serialising the body, would leave the
        // install permanently unable to authenticate with no way back short of a new key.
        //
        // The secret is kept and the failure is surfaced instead. A genuinely wrong secret
        // keeps failing visibly, which is recoverable; a discarded one is not.
        if (in_array($code_str, ['BAD_SIGNATURE', 'MISSING_SIGNATURE'], true)) {
            $count = (int) get_transient(self::TRANSIENT_SIG_FAILURES);
            set_transient(self::TRANSIENT_SIG_FAILURES, $count + 1, DAY_IN_SECONDS);
        } elseif ($code_str === 'ALREADY_UPGRADED') {
            // The engine has this key in hmac mode but this install has no usable secret —
            // a restored backup, or rotated wp-config salts that made the stored ciphertext
            // undecryptable. It cannot be recovered, so stop retrying and say so.
            update_option(self::OPT_SECRET_LOST, 1, false);
            delete_transient('guardify_upgrade_attempted');
        }

        return $this->fail($code_str, $message);
    }

    /**
     * Whether a request can safely be sent again. Reads always can; writes only when
     * repeating them cannot create a duplicate consignment, SMS or OTP.
     */
    private function is_retryable($method, $path) {
        if ($method === 'GET') {
            return true;
        }
        return !in_array($path, self::NON_IDEMPOTENT, true);
    }

    /**
     * Record the failure for the settings screen and return it to the caller.
     */
    private function fail($code, $message) {
        // A transient, not an option.
        //
        // During an engine outage every concurrent checkout reaches this path, and writing
        // one shared wp_options row from all of them serialises on a row lock precisely when
        // the site is already struggling. A transient can land in object cache instead of the
        // database, and expires on its own so a stale error does not sit in the admin
        // indefinitely.
        set_transient(self::OPT_LAST_ERROR, [
            'code'    => (string) $code,
            'message' => (string) $message,
            'time'    => time(),
        ], 15 * MINUTE_IN_SECONDS);

        return ['success' => false, 'error' => $message, 'code' => $code];
    }
}
