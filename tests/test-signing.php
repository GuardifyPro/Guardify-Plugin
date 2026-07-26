<?php
/**
 * Signing and secret-storage tests.
 *
 * The cross-language vector here is asserted byte-for-byte against
 * TestCrossLanguageVector in the engine (engine/pkg/signing/signing_test.go).
 * If the two ever disagree, every merchant's requests stop authenticating — so the
 * vector is duplicated on purpose, and both tests must be updated together.
 *
 * Run: php tests/test-signing.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-guardify-crypto.php';
require_once __DIR__ . '/../includes/class-guardify-signer.php';

// ─── Cross-language vector ───────────────────────────────────────────────────

$secret = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';
$body   = '{"phones":["01712345678","01812345678"]}';

gf_assert_same(
    '72d21feb1cd2547c337abeeb7c93d051852a3fefa22d4e85d4a601932229cb1e',
    Guardify_Signer::sign($secret, 'POST', '/api/v1/phone-sync', 1735689600, '0123456789abcdef0123456789abcdef', $body),
    'cross-language vector must match the engine (see TestCrossLanguageVector)'
);

// ─── Canonical string shape ──────────────────────────────────────────────────

$canonical = Guardify_Signer::canonical('post', '/api/v1/x?a=1', 1700000000, 'n0123456789abcdef', 'hi');
$lines     = explode("\n", $canonical);

gf_assert_same(5, count($lines), 'canonical string has exactly 5 fields');
gf_assert_same('POST', $lines[0], 'method is upper-cased');
gf_assert_same('/api/v1/x?a=1', $lines[1], 'path retains its query string');
gf_assert_same('1700000000', $lines[2], 'timestamp field');
gf_assert_same('n0123456789abcdef', $lines[3], 'nonce field');
gf_assert_same(
    '8f434346648f6b96df89dda901c5176b10a6d83961dd3c1ac88b59b2dc327aa4',
    $lines[4],
    'body digest is sha256 of the raw body'
);

// An empty body must digest as sha256('') rather than being skipped, otherwise GET
// and POST-with-empty-body would produce interchangeable signatures.
gf_assert_same(
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    explode("\n", Guardify_Signer::canonical('GET', '/api/v1/auth/status', 1, 'nonce0123456789a', ''))[4],
    'empty body digests as sha256 of the empty string'
);

// ─── Signature sensitivity ───────────────────────────────────────────────────

$base = Guardify_Signer::sign($secret, 'POST', '/api/v1/courier/send', 1700000000, 'nonce0123456789a', '{"cod":1500}');

$variants = [
    'verb change'      => Guardify_Signer::sign($secret, 'GET', '/api/v1/courier/send', 1700000000, 'nonce0123456789a', '{"cod":1500}'),
    'path change'      => Guardify_Signer::sign($secret, 'POST', '/api/v1/backup/download', 1700000000, 'nonce0123456789a', '{"cod":1500}'),
    'timestamp change' => Guardify_Signer::sign($secret, 'POST', '/api/v1/courier/send', 1700000001, 'nonce0123456789a', '{"cod":1500}'),
    'nonce change'     => Guardify_Signer::sign($secret, 'POST', '/api/v1/courier/send', 1700000000, 'nonce0123456789b', '{"cod":1500}'),
    'body change'      => Guardify_Signer::sign($secret, 'POST', '/api/v1/courier/send', 1700000000, 'nonce0123456789a', '{"cod":1}'),
    'secret change'    => Guardify_Signer::sign(str_repeat('f', 64), 'POST', '/api/v1/courier/send', 1700000000, 'nonce0123456789a', '{"cod":1500}'),
];

foreach ($variants as $label => $sig) {
    gf_assert($sig !== $base, "signature changes on {$label}");
    gf_assert_same(64, strlen($sig), "signature for {$label} is 64 hex chars");
}

// ─── Headers ─────────────────────────────────────────────────────────────────

$signed = Guardify_Signer::headers('gfk_abc', $secret, 'GET', '/api/v1/auth/status', '');

foreach (['X-GF-Key', 'X-GF-Domain', 'X-GF-Version', 'X-GF-Timestamp', 'X-GF-Nonce', 'X-GF-Signature'] as $h) {
    gf_assert(isset($signed[$h]) && $signed[$h] !== '', "signed request sets {$h}");
}
gf_assert_same(32, strlen($signed['X-GF-Nonce']), 'nonce is 32 hex chars (16 bytes)');

// Two consecutive requests must never reuse a nonce, or the engine's replay
// protection would reject the second one.
$again = Guardify_Signer::headers('gfk_abc', $secret, 'GET', '/api/v1/auth/status', '');
gf_assert($signed['X-GF-Nonce'] !== $again['X-GF-Nonce'], 'each request gets a fresh nonce');

// A legacy key has no secret and must not emit signature headers at all.
$legacy = Guardify_Signer::headers('gp_old', '', 'GET', '/api/v1/auth/status', '');
gf_assert(!isset($legacy['X-GF-Signature']), 'legacy mode omits the signature header');
gf_assert(isset($legacy['X-GF-Key']), 'legacy mode still sends the key');

// ─── Domain normalisation (must agree with middleware.NormalizeDomain) ───────

$domain_cases = [
    'https://shop.example.com'      => 'shop.example.com',
    'https://www.shop.example.com'  => 'shop.example.com',
    'http://WWW.Shop.Example.COM/x' => 'shop.example.com',
    'https://shop.example.com:8443' => 'shop.example.com',
];

foreach ($domain_cases as $home => $expected) {
    $GLOBALS['gf_test_home_url'] = $home;
    gf_assert_same($expected, Guardify_Signer::site_domain(), "site_domain({$home})");
}
$GLOBALS['gf_test_home_url'] = 'https://shop.example.com';

// ─── Secret storage ──────────────────────────────────────────────────────────

gf_assert(Guardify_Crypto::is_available(), 'crypto is available with salts defined');

$plain     = '9f8e7d6c5b4a39281706f5e4d3c2b1a09f8e7d6c5b4a39281706f5e4d3c2b1a0';
$encrypted = Guardify_Crypto::encrypt($plain);

gf_assert($encrypted !== $plain, 'encrypt() changes the value');
gf_assert(strpos($encrypted, 'gfenc1:') === 0, 'ciphertext is version-prefixed');
gf_assert(strpos($encrypted, $plain) === false, 'ciphertext does not contain the plaintext');
gf_assert_same($plain, Guardify_Crypto::decrypt($encrypted), 'decrypt() round-trips');

// GCM uses a random nonce per call, so the same input must not produce the same
// ciphertext twice — otherwise identical secrets would be visible as identical rows.
gf_assert(
    Guardify_Crypto::encrypt($plain) !== $encrypted,
    'encrypting twice yields different ciphertext'
);

// Values written before encryption existed are stored bare and must still be read.
gf_assert_same($plain, Guardify_Crypto::decrypt($plain), 'legacy plaintext values still decrypt');

// Tampering must fail closed (GCM auth tag), never return partial plaintext.
$corrupted = 'gfenc1:' . base64_encode('short');
gf_assert_same('', Guardify_Crypto::decrypt($corrupted), 'truncated ciphertext returns empty');

$valid_raw = base64_decode(substr($encrypted, 7), true);
$flipped   = 'gfenc1:' . base64_encode(substr($valid_raw, 0, -1) . chr(ord(substr($valid_raw, -1)) ^ 0xFF));
gf_assert_same('', Guardify_Crypto::decrypt($flipped), 'bit-flipped ciphertext is rejected by the auth tag');

gf_assert_same('', Guardify_Crypto::decrypt(''), 'empty input returns empty');
gf_assert_same(32, strlen(Guardify_Crypto::random_hex(16)), 'random_hex(16) returns 32 chars');
gf_assert(
    Guardify_Crypto::random_hex(16) !== Guardify_Crypto::random_hex(16),
    'random_hex does not repeat'
);

exit(gf_test_summary('signing'));
