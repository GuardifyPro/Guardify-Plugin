<?php
defined('ABSPATH') || exit;

/**
 * Guardify_Signer — request signing, mirrored from the engine's pkg/signing.
 *
 * The canonical string is five newline-separated fields:
 *
 *   METHOD \n PATH_WITH_QUERY \n TIMESTAMP \n NONCE \n sha256_hex(BODY)
 *
 * and the signature is hex(HMAC-SHA256(secret, canonical)).
 *
 * Binding all five fields means a captured signature cannot be moved to another
 * route, switched to a different HTTP verb, or have its payload edited. The
 * timestamp bounds the replay window and the engine rejects a repeated nonce inside
 * that window, which closes it completely.
 *
 * Both implementations assert the same test vector — see tests/test-signing.php here
 * and TestCrossLanguageVector in the engine. If one side changes, a test fails
 * instead of authentication silently breaking for every merchant.
 */
class Guardify_Signer {

    const HEADER_KEY       = 'X-GF-Key';
    const HEADER_TIMESTAMP = 'X-GF-Timestamp';
    const HEADER_NONCE     = 'X-GF-Nonce';
    const HEADER_SIGNATURE = 'X-GF-Signature';
    const HEADER_DOMAIN    = 'X-GF-Domain';
    const HEADER_VERSION   = 'X-GF-Version';

    /**
     * Build the canonical string that gets signed.
     *
     * @param string $method          HTTP verb.
     * @param string $path_with_query Path including the query string, exactly as sent.
     * @param int    $timestamp       Unix seconds.
     * @param string $nonce           Per-request random value.
     * @param string $body            Raw request body ('' for bodyless requests).
     * @return string
     */
    public static function canonical($method, $path_with_query, $timestamp, $nonce, $body = '') {
        return strtoupper($method) . "\n"
            . $path_with_query . "\n"
            . (string) intval($timestamp) . "\n"
            . $nonce . "\n"
            . hash('sha256', (string) $body);
    }

    /**
     * Compute the hex signature for a request.
     *
     * @return string 64 lowercase hex characters.
     */
    public static function sign($secret, $method, $path_with_query, $timestamp, $nonce, $body = '') {
        return hash_hmac(
            'sha256',
            self::canonical($method, $path_with_query, $timestamp, $nonce, $body),
            $secret
        );
    }

    /**
     * Build the full set of authentication headers for one request.
     *
     * @param string $api_key
     * @param string $secret          Signing secret; empty means legacy bearer mode.
     * @param string $method
     * @param string $path_with_query
     * @param string $body
     * @return array<string,string>
     */
    public static function headers($api_key, $secret, $method, $path_with_query, $body = '') {
        $headers = [
            self::HEADER_KEY     => $api_key,
            self::HEADER_DOMAIN  => self::site_domain(),
            self::HEADER_VERSION => defined('GUARDIFY_VERSION') ? GUARDIFY_VERSION : '',
        ];

        if ($secret === '' || $secret === null) {
            return $headers; // legacy key: bearer only, engine still accepts it
        }

        $timestamp = time();
        $nonce     = Guardify_Crypto::random_hex(16);

        $headers[self::HEADER_TIMESTAMP] = (string) $timestamp;
        $headers[self::HEADER_NONCE]     = $nonce;
        $headers[self::HEADER_SIGNATURE] = self::sign($secret, $method, $path_with_query, $timestamp, $nonce, $body);

        return $headers;
    }

    /**
     * The site's bare host, normalised the same way the engine normalises it:
     * lowercase, no scheme, no path, no port, no leading "www.".
     *
     * @return string
     */
    public static function site_domain() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $host = strtolower($host);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return trim($host, '.');
    }
}
