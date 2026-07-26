<?php
/**
 * Domain change tests.
 *
 * The plugin-side normaliser decides what "newshop.com.bd" means when a merchant pastes
 * "https://www.newshop.com.bd/wp-admin/". Get it wrong in the permissive direction and the
 * site's every URL is rewritten to something nobody can reach; get it wrong in the strict
 * direction and the wizard rejects the most natural thing to type.
 *
 * Run: php tests/test-domain.php
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('add_action')) {
    function add_action() { return true; }
}

require_once __DIR__ . '/../includes/class-guardify-domain.php';

class GF_Domain_Probe extends Guardify_Domain {
    public static function build() {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
    public function t_normalize($raw) { return $this->normalize($raw); }
}

$d = GF_Domain_Probe::build();

// ─── What a merchant actually types ──────────────────────────────────────────

// All of these mean the same site. Rejecting any of them is a wizard that fails on the
// most natural input, at the moment the merchant is least willing to retry.
$same = [
    'newshop.com.bd',
    'NewShop.com.bd',
    '  newshop.com.bd  ',
    'https://newshop.com.bd',
    'http://newshop.com.bd',
    'https://newshop.com.bd/',
    'https://www.newshop.com.bd',
    'www.newshop.com.bd',
    '//newshop.com.bd',
    'https://newshop.com.bd/wp-admin/admin.php?page=guardify',
    'newshop.com.bd#top',
];

foreach ($same as $raw) {
    gf_assert_same('newshop.com.bd', $d->t_normalize($raw), "normalises: {$raw}");
}

// ─── What must be refused ────────────────────────────────────────────────────

// Anything not a plain hostname is a typo or a paste that brought something with it.
// Silently stripping the extra would move the site somewhere the merchant did not ask for,
// and the restore is not reversible from their side once the swap lands.
$refused = [
    ''                        => 'empty input',
    '   '                     => 'whitespace only',
    'newshop'                 => 'a single label reaches nobody',
    'localhost'               => 'localhost is not a site',
    'new shop.com'            => 'a space is a typo',
    'new_shop.com'            => 'underscores are not valid in a hostname',
    'newshop..com'            => 'an empty label',
    '-newshop.com'            => 'a label cannot start with a hyphen',
    'newshop-.com'            => 'a label cannot end with a hyphen',
    'newshop.com<script>'     => 'markup in the input',
];

foreach ($refused as $raw => $why) {
    gf_assert_same('', $d->t_normalize($raw), "refused ({$why}): " . var_export($raw, true));
}

// ─── Tidied rather than refused ──────────────────────────────────────────────

// A leading dot, a stray newline from a paste, a port left on from a dev URL — each of
// these has one obvious intended meaning, and refusing them would send the merchant back to
// retype something they already typed correctly enough.
gf_assert_same('newshop.com', $d->t_normalize('.newshop.com'), 'a leading dot is trimmed');
gf_assert_same('newshop.com.bd', $d->t_normalize("newshop.com.bd\n"), 'a trailing newline is trimmed');
gf_assert_same('newshop.com.bd', $d->t_normalize("\tnewshop.com.bd\t"), 'tabs are trimmed');
gf_assert_same('newshop.com.bd', $d->t_normalize('newshop.com.bd:8080'), 'a port is dropped');

// An IP address survives the normaliser — it is a well-formed hostname by shape — and is
// refused by the engine instead. The judgement lives in one place on purpose: duplicating
// it here is how the two ends drift apart and start disagreeing about what is allowed.
gf_assert_same('192.168.1.1', $d->t_normalize('192.168.1.1:8080'), 'an IP is normalised, not mangled');

// ─── Punycode ────────────────────────────────────────────────────────────────

// A Bengali or Arabic domain reaches the browser as punycode, and that is what WordPress
// stores. Rejecting it would lock out exactly the merchants this product is built for.
gf_assert_same('xn--fiqs8s.com', $d->t_normalize('https://xn--fiqs8s.com/'), 'punycode survives');

// ─── State option ────────────────────────────────────────────────────────────

// The change spans a backup and a restore, both of which outlive the request that started
// them. Losing the state means a merchant with a half-moved site and no screen that admits
// it is happening.
gf_assert(
    defined('Guardify_Domain::STATE_OPTION') || Guardify_Domain::STATE_OPTION !== '',
    'the in-flight change has somewhere to live across requests'
);

gf_test_summary('domain');
