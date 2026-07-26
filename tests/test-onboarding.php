<?php
/**
 * Setup wizard preset tests.
 *
 * The presets decide what a merchant's shop does on its first day with Guardify installed.
 * A preset that quietly refuses real customers is the worst possible outcome: the merchant
 * loses money, blames the plugin, uninstalls it, and tells other merchants. These
 * assertions exist to make that impossible to introduce by editing an array.
 *
 * Run: php tests/test-onboarding.php
 */

require_once __DIR__ . '/bootstrap.php';

// The class registers WordPress hooks in its constructor and reads GUARDIFY_PATH when
// rendering. Only presets() is under test here and it is static, so the file is loaded with
// the hook functions stubbed rather than the class being torn apart to suit the test.
if (!function_exists('add_action')) {
    function add_action() { return true; }
}
if (!function_exists('add_submenu_page')) {
    function add_submenu_page() { return ''; }
}

require_once __DIR__ . '/../includes/class-guardify-onboarding.php';

$presets = Guardify_Onboarding::presets();

// ─── Shape ───────────────────────────────────────────────────────────────────

gf_assert_same(['observe', 'balanced', 'strict'], array_keys($presets), 'three levels, least to most careful');

foreach ($presets as $name => $preset) {
    foreach (['label', 'summary', 'best_for', 'options'] as $field) {
        gf_assert(!empty($preset[$field]), "preset '{$name}' has a {$field}");
    }
    // Every label the merchant reads is Bengali. A wizard is the first thing they see, and
    // an English word in it says the product was not built for them.
    gf_assert(
        preg_match('/[\x{0980}-\x{09FF}]/u', $preset['label']) === 1,
        "preset '{$name}' is labelled in Bengali"
    );
}

// ─── Nothing refuses an order on day one ─────────────────────────────────────

// This is the invariant the whole wizard rests on. Dry-run means Guardify scores and
// records but never turns a customer away, so a merchant can watch the verdicts for a few
// days and decide for themselves. Turning enforcement on is a deliberate second act.
foreach ($presets as $name => $preset) {
    $o = $preset['options'];

    gf_assert_same('yes', $o['guardify_smart_filter_dry_run'],
        "preset '{$name}' starts in dry-run — no preset may refuse an order during setup");

    // Most customers are new to any given shop. Treating that as a risk signal refuses a
    // large share of legitimate first-time buyers, which is precisely the failure that
    // makes a merchant conclude the plugin is broken.
    gf_assert_same('yes', $o['guardify_smart_filter_skip_new'],
        "preset '{$name}' does not act on customers with no history");

    // VPN detection is off everywhere, including the strictest level. Bangladeshi mobile
    // networks put large numbers of ordinary customers behind carrier-grade NAT that reads
    // as a VPN, so enforcing it refuses real buyers on the operator's behalf.
    gf_assert_same('no', $o['guardify_vpn_block_enabled'],
        "preset '{$name}' leaves VPN blocking off — BD mobile NAT makes it unsafe by default");
}

// ─── Each level does more than the one below it ──────────────────────────────

$observe  = $presets['observe']['options'];
$balanced = $presets['balanced']['options'];
$strict   = $presets['strict']['options'];

// Observe is exactly what its name says: watch and report, change nothing.
gf_assert_same('no', $observe['guardify_repeat_blocker_enabled'], 'observe blocks nothing');
gf_assert_same('no', $observe['guardify_otp_enabled'], 'observe asks for nothing');
gf_assert_same('no', $observe['guardify_fraud_detection_enabled'], 'observe records no fraud');

// Balanced adds the rule with essentially no false positives: the same number ordering
// repeatedly within an hour is a bot or a mistake, not a customer.
gf_assert_same('yes', $balanced['guardify_repeat_blocker_enabled'], 'balanced catches repeat submissions');
gf_assert_same('yes', $balanced['guardify_fraud_detection_enabled'], 'balanced keeps a fraud record');
gf_assert_same('no', $balanced['guardify_otp_enabled'], 'balanced does not add friction to checkout');

// Strict adds OTP, and only on risky orders. OTP on every checkout is a conversion tax
// paid by the 95% of customers who are fine.
gf_assert_same('yes', $strict['guardify_otp_enabled'], 'strict verifies by OTP');
gf_assert_same('risky', $strict['guardify_otp_scope'], 'OTP applies to risky orders only, never all of them');
gf_assert_same('otp', $strict['guardify_smart_filter_action'], 'strict escalates risky orders to OTP');

// Visibility is on at every level — it is the whole reason to install this.
foreach ($presets as $name => $preset) {
    gf_assert_same('yes', $preset['options']['guardify_report_column_enabled'],
        "preset '{$name}' shows the courier verdict on the orders list");
    gf_assert_same('yes', $preset['options']['guardify_smart_filter_enabled'],
        "preset '{$name}' scores orders");
}

// ─── The action never exceeds what the level promises ────────────────────────

// The summary text is what the merchant reads before choosing. If an option escalates past
// what that sentence describes, the wizard has misled them about their own shop.
$blocking = ['block', 'advance_payment'];
foreach ($presets as $name => $preset) {
    gf_assert(
        !in_array($preset['options']['guardify_smart_filter_action'], $blocking, true),
        "preset '{$name}' does not default to an action that stops a sale"
    );
}

// ─── Every option written is one the plugin reads ────────────────────────────

// A typo in an option name is silent: the preset appears to apply, the setting never takes
// effect, and the merchant believes they are protected. Each key is checked against an
// actual get_option call in the source.
$sources = '';
foreach (glob(__DIR__ . '/../includes/*.php') as $file) {
    $sources .= file_get_contents($file);
}
$sources .= file_get_contents(__DIR__ . '/../guardify-pro.php');
foreach (glob(__DIR__ . '/../templates/*.php') as $file) {
    $sources .= file_get_contents($file);
}

foreach ($presets as $name => $preset) {
    foreach (array_keys($preset['options']) as $option) {
        gf_assert(
            strpos($sources, "'{$option}'") !== false,
            "option {$option} (preset '{$name}') is read somewhere in the plugin"
        );
    }
}

// ─── Scan bounds ─────────────────────────────────────────────────────────────

// The retroactive scan runs on the merchant's server against their live orders. Unbounded,
// it is a way to make a first-run wizard take down a shop.
gf_assert(Guardify_Onboarding::SCAN_LIMIT <= 500, 'the scan looks at a bounded number of orders');
gf_assert(Guardify_Onboarding::SCAN_CHUNK <= 100,
    'each scan request stays inside the batch endpoint cap and an admin-ajax timeout');
gf_assert(Guardify_Onboarding::SCAN_CHUNK < Guardify_Onboarding::SCAN_LIMIT,
    'the scan takes more than one request, so it can report progress');

gf_test_summary('onboarding');
