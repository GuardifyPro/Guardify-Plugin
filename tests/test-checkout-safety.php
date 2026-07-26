<?php
/**
 * Checkout-safety invariants.
 *
 * Everything here guards the same failure: an order that a real customer tried to place and
 * that never became a sale. `woocommerce_checkout_process` runs before the order row exists,
 * so a request that hangs past PHP's max_execution_time — commonly 30s on the shared hosting
 * most Bangladeshi shops use — produces a fatal error, no order, and no trace for the
 * merchant. A refused sale is permanent; an unscreened one is a risk the merchant was already
 * carrying. These tests encode that asymmetry.
 *
 * Run: php tests/test-checkout-safety.php
 */

require_once __DIR__ . '/bootstrap.php';

// The classes under test call WooCommerce and translation helpers at load time only inside
// methods we do not exercise here, so the stubs stay minimal. If a test starts needing more
// WooCommerce than this, that is a signal the unit has grown a dependency worth looking at.
if (!function_exists('esc_html')) {
    function esc_html($t) { return $t; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($t, $d = '') { return $t; }
}
if (!function_exists('add_action')) {
    function add_action() { return true; }
}
if (!function_exists('has_action')) {
    function has_action() { return false; }
}
if (!function_exists('wc_add_notice')) {
    function wc_add_notice($msg, $type = 'notice') {
        $GLOBALS['gf_notices'][] = ['message' => $msg, 'type' => $type];
    }
}
$GLOBALS['gf_notices'] = [];

require_once __DIR__ . '/../includes/class-guardify-crypto.php';
require_once __DIR__ . '/../includes/class-guardify-signer.php';
require_once __DIR__ . '/../includes/class-guardify-phone-util.php';
require_once __DIR__ . '/../includes/class-guardify-api.php';
require_once __DIR__ . '/../includes/class-guardify-smart-filter.php';

// ─── The checkout request budget ─────────────────────────────────────────────

$opts = Guardify_API::checkout_opts();

gf_assert(isset($opts['timeout']), 'checkout_opts sets a timeout');
gf_assert(isset($opts['retries']), 'checkout_opts sets a retry count');

// Two calls can be registered on woocommerce_checkout_process (risk assessment and the VPN
// check), so the per-call budget has to assume it is not alone. Anything above 3s puts the
// combined worst case within reach of a 30s execution limit once TLS and DNS are counted.
gf_assert(
    $opts['timeout'] > 0 && $opts['timeout'] <= 3,
    sprintf('checkout timeout is at most 3s (got %s)', var_export($opts['timeout'], true))
);

// Retries are the specific mistake this guards. Three attempts at a 20s timeout is 60s for a
// single call, and two such calls exceed any shared-hosting execution limit — the customer
// sees a fatal error and the merchant never learns an order was attempted.
gf_assert_same(1, $opts['retries'], 'checkout requests must not retry');

$worst_case = $opts['timeout'] * $opts['retries'] * 2; // two hooks on the same action
gf_assert(
    $worst_case <= 6,
    sprintf('combined worst-case checkout latency is at most 6s (got %.1fs)', $worst_case)
);

// ─── Merchant ceiling clamps the engine's recommendation ─────────────────────

// A merchant who chose "flag only" must never have an order blocked, whatever the engine
// recommends. Their setting is a ceiling, not a hint.
foreach (['allow', 'flag', 'otp', 'advance_payment', 'block'] as $recommended) {
    gf_assert_same(
        $recommended === 'allow' ? 'allow' : 'flag',
        Guardify_Smart_Filter::effective_action($recommended, 'flag'),
        sprintf('ceiling "flag" clamps a recommended "%s"', $recommended)
    );
}

// A ceiling of block permits everything, unchanged.
foreach (['allow', 'flag', 'otp', 'advance_payment', 'block'] as $recommended) {
    gf_assert_same(
        $recommended,
        Guardify_Smart_Filter::effective_action($recommended, 'block'),
        sprintf('ceiling "block" passes through "%s"', $recommended)
    );
}

// A softer recommendation is never escalated to the ceiling. The engine downgrades a block to
// an advance-payment request when the evidence is too thin to justify refusing the sale, and
// the plugin must not undo that.
gf_assert_same(
    'advance_payment',
    Guardify_Smart_Filter::effective_action('advance_payment', 'block'),
    'a softer recommendation is not escalated to the ceiling'
);
gf_assert_same(
    'allow',
    Guardify_Smart_Filter::effective_action('allow', 'block'),
    'allow stays allow even when blocking is permitted'
);

// An action from a newer engine that this plugin version does not recognise must not be
// interpreted as a block. Failing open on an unknown verdict costs a screening; failing
// closed costs the sale.
foreach (['', 'quarantine', 'BLOCK', 'reject', 'null'] as $unknown) {
    gf_assert_same(
        'allow',
        Guardify_Smart_Filter::effective_action($unknown, 'block'),
        sprintf('unknown action "%s" is treated as allow', $unknown)
    );
}

// An unrecognised merchant setting falls back to flag rather than to block. A corrupted
// option must not silently start refusing customers.
gf_assert_same(
    'flag',
    Guardify_Smart_Filter::effective_action('block', 'garbage'),
    'an unrecognised ceiling falls back to flag, not block'
);

// ─── Phone handling at the checkout boundary ─────────────────────────────────

// The assessment is keyed on the phone number, so a number that normalises differently here
// than on the engine would score the wrong customer.
$expected = '01712345678';
foreach (['01712345678', '+8801712345678', '8801712345678', '1712345678', '017 1234 5678', '017-1234-5678'] as $raw) {
    gf_assert_same($expected, Guardify_Phone_Util::clean($raw), sprintf('clean(%s)', $raw));
}

// Invalid input must yield null rather than a partially-normalised string, which would
// otherwise be sent to the engine and score a nonexistent customer.
foreach (['', 'abc', '0171234567', '01012345678', '+919876543210'] as $bad) {
    gf_assert_same(null, Guardify_Phone_Util::clean($bad), sprintf('clean(%s) rejects', $bad));
}

// ─── Failure records must not contend on wp_options ──────────────────────────

// During an engine outage every concurrent checkout reaches the failure path. Writing one
// shared wp_options row from all of them serialises on a row lock at exactly the moment the
// site is already struggling, so the record goes to a transient instead.
$api_source = file_get_contents(__DIR__ . '/../includes/class-guardify-api.php');
gf_assert(
    strpos($api_source, 'update_option(self::OPT_LAST_ERROR') === false,
    'the API error record does not write to wp_options on every failure'
);
gf_assert(
    strpos($api_source, 'set_transient(self::OPT_LAST_ERROR') !== false,
    'the API error record uses a transient'
);

// ─── Defaults are the safe direction ────────────────────────────────────────

$filter_source = file_get_contents(__DIR__ . '/../includes/class-guardify-smart-filter.php');

// Activating a plugin must not begin refusing a stranger's customers on thresholds the
// merchant never chose.
gf_assert(
    strpos($filter_source, "get_option('guardify_smart_filter_action', 'flag')") !== false,
    'smart filter defaults to flag, not block'
);
gf_assert(
    strpos($filter_source, "get_option('guardify_smart_filter_dry_run', 'yes')") !== false,
    'smart filter starts in dry-run so the merchant sees the effect before opting in'
);

$vpn_source = file_get_contents(__DIR__ . '/../includes/class-guardify-vpn-block.php');

// Bangladeshi mobile operators run large carrier-grade NAT ranges that public IP-reputation
// feeds frequently classify as proxies. Enforcing on that signal by default refuses ordinary
// customers on their phones, in a mobile-first market, invisibly.
gf_assert(
    strpos($vpn_source, "get_option('guardify_vpn_block_enforce', 'no')") !== false,
    'VPN detection is advisory unless the merchant explicitly enables enforcement'
);

// Every checkout-path call must carry the tight budget.
foreach (['class-guardify-smart-filter.php', 'class-guardify-vpn-block.php'] as $file) {
    $src = file_get_contents(__DIR__ . '/../includes/' . $file);
    gf_assert(
        strpos($src, 'Guardify_API::checkout_opts()') !== false,
        sprintf('%s uses the checkout request budget', $file)
    );
}

// ─── OTP must not be able to refuse every order ─────────────────────────────

$otp_source = file_get_contents(__DIR__ . '/../includes/class-guardify-otp.php');

// With OTP enabled and delivery broken — an exhausted SMS balance, a gateway outage — no
// customer can verify, so demanding verification refuses every order on the shop.
gf_assert(
    strpos($otp_source, 'delivery_broken()') !== false,
    'OTP stops gating checkout when delivery is failing'
);
gf_assert(
    strpos($otp_source, "get_option('guardify_otp_scope', 'risky')") !== false,
    'OTP applies to risky customers by default rather than to every order'
);

// ─── No engine call may bypass signing ───────────────────────────────────────

// A request carrying only X-GF-Key is unsigned, and the engine rejects it outright once the
// key is in hmac mode. The backup and restore paths did exactly that, so both features broke
// for every signed install — invisibly, because they run from cron.
foreach (glob(__DIR__ . '/../includes/*.php') as $file) {
    $src  = file_get_contents($file);
    $name = basename($file);

    // The signer defines the header and the API client documents it; everything else must go
    // through one of those two.
    if (in_array($name, ['class-guardify-signer.php', 'class-guardify-api.php'], true)) {
        continue;
    }

    gf_assert(
        !preg_match("/'X-GF-Key'\\s*=>/", $src),
        sprintf('%s does not hand-roll an unsigned X-GF-Key header', $name)
    );
}

// ─── A rejected signature must not destroy the secret ────────────────────────

// Deleting it looks like self-healing but is a one-way trip: the engine keeps the key in hmac
// mode, and /auth/upgrade refuses unsigned calls and is single-use. One transient rejection —
// a WAF rewriting the request line, a proxy re-serialising the body — would leave the install
// permanently unable to authenticate.
gf_assert(
    strpos($api_source, "delete_option(self::OPT_SECRET);\n            update_option(self::OPT_AUTH_MODE, 'legacy'") === false,
    'a rejected signature does not delete the stored signing secret'
);
gf_assert(
    strpos($api_source, 'TRANSIENT_SIG_FAILURES') !== false,
    'rejected signatures are counted and surfaced rather than acted on destructively'
);

// The upgrade issues the secret exactly once, so a retry after a slow-but-successful attempt
// would spend the only chance to collect it.
gf_assert(
    strpos($api_source, "'/api/v1/auth/upgrade',") !== false,
    'the upgrade handshake is marked non-idempotent'
);

exit(gf_test_summary('checkout-safety'));
