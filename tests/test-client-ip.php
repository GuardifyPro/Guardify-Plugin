<?php
/**
 * Client IP tests.
 *
 * This decides which address a visitor is judged on, so it is the difference between IP
 * blocking working and IP blocking being decoration. Three copies of this logic used to walk
 * CF-Connecting-IP, X-Real-IP and X-Forwarded-For and return the first thing that parsed —
 * headers anyone can send. That meant a blocked customer could step around the block by
 * typing an address into their browser's developer tools, and could have an innocent third
 * party's address recorded against their own abusive orders.
 *
 * Run: php tests/test-client-ip.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-guardify-client-ip.php';

/** Reset $_SERVER and the option between cases. */
function gf_ip_env(array $server, $trusted = '') {
    $_SERVER = $server;
    update_option('guardify_trusted_proxy_header', $trusted);
}

// ─── The default: only the connection itself is believed ─────────────────────

// No trusted header configured, which is every site until a merchant says otherwise.
gf_ip_env([
    'REMOTE_ADDR'           => '103.108.140.10',
    'HTTP_X_FORWARDED_FOR'  => '8.8.8.8',
    'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
    'HTTP_X_REAL_IP'        => '9.9.9.9',
]);

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'ignores every proxy header when none is trusted'
);

// The whole point, stated as its own case: a visitor cannot choose their own address.
gf_ip_env([
    'REMOTE_ADDR'          => '103.108.140.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
]);
gf_assert(
    Guardify_Client_IP::get() !== '203.0.113.99',
    'a blocked visitor cannot pick a clean address with one header'
);

// ─── With a proxy configured ─────────────────────────────────────────────────

gf_ip_env([
    'REMOTE_ADDR'           => '172.68.0.1',   // Cloudflare's edge, a private-ish hop
    'HTTP_CF_CONNECTING_IP' => '103.108.140.10',
], 'HTTP_CF_CONNECTING_IP');

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'uses the nominated header when the merchant has configured one'
);

// Only the nominated one. A site behind Cloudflare should not also start believing
// X-Forwarded-For, which Cloudflare passes through from the client unchanged.
gf_ip_env([
    'REMOTE_ADDR'           => '172.68.0.1',
    'HTTP_CF_CONNECTING_IP' => '103.108.140.10',
    'HTTP_X_FORWARDED_FOR'  => '203.0.113.99',
], 'HTTP_CF_CONNECTING_IP');

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'other headers stay untrusted even when one is configured'
);

// X-Forwarded-For is a chain; the left-most entry is the client as the first proxy saw it.
gf_ip_env([
    'REMOTE_ADDR'          => '10.0.0.5',
    'HTTP_X_FORWARDED_FOR' => '103.108.140.10, 172.68.0.1, 10.0.0.5',
], 'HTTP_X_FORWARDED_FOR');

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'reads the left-most address out of an X-Forwarded-For chain'
);

// A header naming something that is not an address falls back rather than returning junk.
gf_ip_env([
    'REMOTE_ADDR'           => '103.108.140.10',
    'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
], 'HTTP_CF_CONNECTING_IP');

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'a malformed trusted header falls back to the connection'
);

// A header naming a private address is not a client address; fall back.
gf_ip_env([
    'REMOTE_ADDR'           => '103.108.140.10',
    'HTTP_CF_CONNECTING_IP' => '192.168.1.5',
], 'HTTP_CF_CONNECTING_IP');

gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'a private address in a trusted header is not treated as the visitor'
);

// ─── Only known headers may be nominated ─────────────────────────────────────

// The value becomes a $_SERVER key, so free text would let a site read something that is not
// an address at all.
gf_ip_env([
    'REMOTE_ADDR'      => '103.108.140.10',
    'HTTP_EVIL_HEADER' => '203.0.113.99',
], 'HTTP_EVIL_HEADER');

gf_assert_same(
    '',
    Guardify_Client_IP::trusted_header(),
    'a header outside the allow-list is not accepted'
);
gf_assert_same(
    '103.108.140.10',
    Guardify_Client_IP::get(),
    'an unrecognised nomination falls back to the connection'
);

foreach (array_keys(Guardify_Client_IP::allowed()) as $gf_header) {
    gf_assert(
        strpos($gf_header, 'HTTP_') === 0,
        "allow-list entry is a request header: {$gf_header}"
    );
}

// ─── No address rather than a wrong one ──────────────────────────────────────

// A site behind a proxy that has not been configured gets nothing. IP features then do
// nothing, which is recoverable and is surfaced as a notice — unlike acting on forged data.
gf_ip_env([
    'REMOTE_ADDR'          => '10.0.0.5',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
]);

gf_assert_same('', Guardify_Client_IP::get(), 'an unconfigured proxied site yields no address');
gf_assert(
    Guardify_Client_IP::needs_proxy_setup(),
    'an unconfigured proxied site is detected so the merchant can be told'
);
gf_assert(!Guardify_Client_IP::is_resolvable(), 'and reports that IP features cannot work');

// A directly-connected site needs no setup and must not be nagged.
gf_ip_env(['REMOTE_ADDR' => '103.108.140.10']);
gf_assert(!Guardify_Client_IP::needs_proxy_setup(), 'a direct site is not asked to configure anything');
gf_assert(Guardify_Client_IP::is_resolvable(), 'a direct site can use IP features');

// Once configured, the warning stops even though REMOTE_ADDR is still private.
gf_ip_env([
    'REMOTE_ADDR'           => '10.0.0.5',
    'HTTP_CF_CONNECTING_IP' => '103.108.140.10',
], 'HTTP_CF_CONNECTING_IP');
gf_assert(!Guardify_Client_IP::needs_proxy_setup(), 'a configured proxied site is not nagged');
gf_assert(Guardify_Client_IP::is_resolvable(), 'and can use IP features again');

// ─── Loopback and link-local ─────────────────────────────────────────────────

foreach (['127.0.0.1', '::1', '169.254.1.1', '10.1.2.3', '172.16.0.1', '192.168.0.1'] as $gf_private) {
    gf_ip_env(['REMOTE_ADDR' => $gf_private]);
    gf_assert_same('', Guardify_Client_IP::get(), "not a public address: {$gf_private}");
}

// IPv6 is a perfectly ordinary client address and must survive.
gf_ip_env(['REMOTE_ADDR' => '2400:cb00:2048:1::c629:d7a2']);
gf_assert_same(
    '2400:cb00:2048:1::c629:d7a2',
    Guardify_Client_IP::get(),
    'an IPv6 visitor is not discarded'
);

// ─── connection_address() ────────────────────────────────────────────────────

// Used for rate-limit keys, where a private address is still a fine key — and where the
// header must never be consulted at all.
gf_ip_env([
    'REMOTE_ADDR'          => '10.0.0.5',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.99',
], 'HTTP_X_FORWARDED_FOR');

gf_assert_same(
    '10.0.0.5',
    Guardify_Client_IP::connection_address(),
    'connection_address reports the real peer, private or not'
);
gf_assert(
    Guardify_Client_IP::connection_address() !== '203.0.113.99',
    'connection_address never reads a header, even a trusted one'
);

gf_ip_env(['REMOTE_ADDR' => 'garbage']);
gf_assert_same('', Guardify_Client_IP::connection_address(), 'an unparseable peer yields nothing');

gf_ip_env([]);
gf_assert_same('', Guardify_Client_IP::get(), 'a request with no address at all yields nothing');
gf_assert_same('', Guardify_Client_IP::connection_address(), 'and no connection address either');

// ─── One implementation, not four ────────────────────────────────────────────

// The bug was three copies drifting apart. A fourth would be a fourth chance to reintroduce
// header trust, so nothing outside this class may read those headers.
$gf_sources = array_merge(
    glob(__DIR__ . '/../includes/*.php'),
    [__DIR__ . '/../guardify-pro.php']
);

foreach ($gf_sources as $gf_file) {
    if (basename($gf_file) === 'class-guardify-client-ip.php') {
        continue;
    }
    $gf_src = file_get_contents($gf_file);
    gf_assert(
        strpos($gf_src, 'HTTP_X_FORWARDED_FOR') === false
            && strpos($gf_src, 'HTTP_CF_CONNECTING_IP') === false
            && strpos($gf_src, 'HTTP_X_REAL_IP') === false,
        'reads no proxy header of its own: ' . basename($gf_file)
    );
}

gf_test_summary('client-ip');
