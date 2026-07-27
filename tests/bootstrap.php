<?php
/**
 * Minimal WordPress stub so plugin units can be tested without a WP install.
 *
 * Only the functions the classes under test actually touch are defined. Anything
 * else is intentionally left undefined: if a test starts needing a new WordPress
 * function, that is a signal the unit has grown a dependency worth looking at.
 */

define('ABSPATH', __DIR__ . '/');
define('GUARDIFY_VERSION', 'test');
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

// wp-config salts, so Guardify_Crypto can derive a key.
define('AUTH_KEY', 'p8Zq2!vR7#tYw4^eL0nX6&mB1@sD3*fG5%hJ9(kA)cV-uI+oP=zN_rT');
define('SECURE_AUTH_KEY', 'aB3dE6gH9jK2mN5pQ8sT1vW4xY7zC0fI3lO6rU9uX2aD5gJ8kM1nP4');
define('LOGGED_IN_KEY', 'Zx9Cv8Bn7Mm6Kk5Jj4Hh3Gg2Ff1Dd0Ss9Aa8Qq7Ww6Ee5Rr4Tt3Yy2Uu1');

$GLOBALS['gf_test_options']    = [];
$GLOBALS['gf_test_transients'] = [];
$GLOBALS['gf_test_home_url']   = 'https://shop.example.com';

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['gf_test_options'])
        ? $GLOBALS['gf_test_options'][$name]
        : $default;
}

function update_option($name, $value, $autoload = null) {
    $GLOBALS['gf_test_options'][$name] = $value;
    return true;
}

function delete_option($name) {
    unset($GLOBALS['gf_test_options'][$name]);
    return true;
}

function get_transient($name) {
    return $GLOBALS['gf_test_transients'][$name] ?? false;
}

function set_transient($name, $value, $ttl = 0) {
    $GLOBALS['gf_test_transients'][$name] = $value;
    return true;
}

function delete_transient($name) {
    unset($GLOBALS['gf_test_transients'][$name]);
    return true;
}

function home_url($path = '') {
    return rtrim($GLOBALS['gf_test_home_url'], '/') . $path;
}

function wp_parse_url($url, $component = -1) {
    return $component === -1 ? parse_url($url) : parse_url($url, $component);
}

function wp_unslash($value) {
    return is_array($value) ? array_map('wp_unslash', $value) : stripslashes((string) $value);
}

function sanitize_text_field($str) {
    return trim(strip_tags((string) $str));
}

function wp_json_encode($data, $flags = 0) {
    return json_encode($data, $flags | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function __($text, $domain = '') {
    return $text;
}

function esc_html__($text, $domain = '') {
    return $text;
}

function esc_attr__($text, $domain = '') {
    return $text;
}

// The real functions do rather more, but the property under test is the one that matters
// for output built by string concatenation: markup in the data must not survive as markup.
function esc_html($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function untrailingslashit($str) {
    return rtrim((string) $str, '/\\');
}

// Filters are pass-through here. The units under test use them to let a site override a
// default, and every test asserts the default, so running the real hook system would only
// add a dependency without adding a check.
function apply_filters($hook, $value) {
    return $value;
}

// mysqli is not loaded in the test image, so the real escaping cannot be used. This
// mirrors what addslashes-based escaping produces for the cases the dump can hit, which
// is enough to assert that a quote in the data does not end the SQL literal early.
function esc_sql($data) {
    return addslashes((string) $data);
}

// ─── Tiny assertion harness ──────────────────────────────────────────────────

$GLOBALS['gf_test_pass'] = 0;
$GLOBALS['gf_test_fail'] = 0;

function gf_assert($condition, $message) {
    if ($condition) {
        $GLOBALS['gf_test_pass']++;
        return true;
    }
    $GLOBALS['gf_test_fail']++;
    fwrite(STDERR, "FAIL: {$message}\n");
    return false;
}

function gf_assert_same($expected, $actual, $message) {
    if ($expected === $actual) {
        $GLOBALS['gf_test_pass']++;
        return true;
    }
    $GLOBALS['gf_test_fail']++;
    fwrite(STDERR, sprintf(
        "FAIL: %s\n  expected: %s\n  actual:   %s\n",
        $message,
        var_export($expected, true),
        var_export($actual, true)
    ));
    return false;
}

function gf_test_summary($suite) {
    $pass = $GLOBALS['gf_test_pass'];
    $fail = $GLOBALS['gf_test_fail'];
    printf("%s: %d passed, %d failed\n", $suite, $pass, $fail);
    return $fail === 0 ? 0 : 1;
}
