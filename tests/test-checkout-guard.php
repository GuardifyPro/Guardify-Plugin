<?php
/**
 * Checkout guard tests.
 *
 * The interesting direction is the false positive. A fake order that gets through costs the
 * merchant one courier attempt; a real customer refused at checkout costs them the sale, the
 * customer, and whatever that customer would have bought later. So most of what is asserted
 * here is that ordinary people are left alone — Bengali names, English names, the mixture of
 * the two people actually write, someone with autofill who finishes in nine seconds.
 *
 * Run: php tests/test-checkout-guard.php
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('add_action')) {
    function add_action() { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter() { return true; }
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') { return 'test-salt-' . $scheme; }
}

require_once __DIR__ . '/../includes/class-guardify-checkout-guard.php';

class GF_Guard_Probe extends Guardify_Checkout_Guard {
    public static function build() {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
    public function t_assess(array $post) { return $this->assess($post); }
    public function t_mash($text) { return $this->looks_like_keyboard_mash($text); }
    public function t_disposable($email) { return $this->is_disposable_email($email); }
    public function t_sign($time) { return $this->sign_timestamp($time); }
    public function t_verify($value) { return $this->verify_timestamp($value); }
}

$g = GF_Guard_Probe::build();

/** A submission from somebody real, filled in at human speed. */
function gf_good_post($g, array $overrides = []) {
    return array_merge([
        Guardify_Checkout_Guard::HONEYPOT_FIELD => '',
        Guardify_Checkout_Guard::TIMING_FIELD   => $g->t_sign(time() - 45),
        'billing_first_name' => 'সাদিয়া',
        'billing_last_name'  => 'ইসলাম',
        'billing_address_1'  => 'বাড়ি ১২, রোড ৫, ধানমন্ডি',
        'billing_email'      => 'sadia@gmail.com',
    ], $overrides);
}

// ─── An ordinary customer scores nothing ─────────────────────────────────────

$clean = $g->t_assess(gf_good_post($g));
gf_assert_same(0, $clean['score'], 'a real customer scores zero');
gf_assert_same([], $clean['signals'], 'and trips no signals');

// ─── The honeypot ────────────────────────────────────────────────────────────

// The one signal with no innocent explanation: the field is positioned off the canvas,
// removed from the tab order and hidden from screen readers, so nothing human reaches it.
$trapped = $g->t_assess(gf_good_post($g, [Guardify_Checkout_Guard::HONEYPOT_FIELD => 'http://spam.example']));
gf_assert(in_array('honeypot', $trapped['signals'], true), 'a filled honeypot is caught');
gf_assert($trapped['score'] >= 100, 'and is decisive on its own');

// Whitespace is not a fill. A browser extension or a form-restoring script can leave a space
// in an untouched field, and refusing a sale over that would be indefensible.
$spaced = $g->t_assess(gf_good_post($g, [Guardify_Checkout_Guard::HONEYPOT_FIELD => '   ']));
gf_assert(!in_array('honeypot', $spaced['signals'], true), 'whitespace in the honeypot is not a fill');

// ─── Timing ──────────────────────────────────────────────────────────────────

$instant = $g->t_assess(gf_good_post($g, [Guardify_Checkout_Guard::TIMING_FIELD => $g->t_sign(time())]));
gf_assert(in_array('too_fast', $instant['signals'], true), 'an instant submission is caught');

// A fast human with autofill must not be. Nine seconds is quick and entirely possible.
$quick = $g->t_assess(gf_good_post($g, [Guardify_Checkout_Guard::TIMING_FIELD => $g->t_sign(time() - 9)]));
gf_assert(!in_array('too_fast', $quick['signals'], true), 'a fast human is left alone');
gf_assert_same(0, $quick['score'], 'and still scores zero');

// The timestamp is signed, so a bot cannot simply post an older one.
$forged = $g->t_assess(gf_good_post($g, [Guardify_Checkout_Guard::TIMING_FIELD => (time() - 600) . '.deadbeef']));
gf_assert(in_array('timing_missing', $forged['signals'], true), 'a forged timestamp does not pass as old');
gf_assert(!in_array('too_fast', $forged['signals'], true), 'a forged timestamp is unverifiable, not fast');

gf_assert_same(null, $g->t_verify(''), 'an empty timing field verifies as nothing');
gf_assert_same(null, $g->t_verify('notanumber.abc'), 'a non-numeric timestamp verifies as nothing');
gf_assert_same(null, $g->t_verify('12345'), 'an unsigned timestamp verifies as nothing');
gf_assert(is_int($g->t_verify($g->t_sign(time() - 30))), 'a properly signed timestamp verifies');

// A missing field is weighted lightly on purpose: a caching plugin, an optimiser stripping
// hidden inputs, or a theme rebuilding the form can all lose it on a genuine checkout. It
// must not reach the threshold by itself.
$missing = $g->t_assess([
    'billing_first_name' => 'করিম',
    'billing_last_name'  => 'উদ্দিন',
]);
gf_assert(in_array('timing_missing', $missing['signals'], true), 'a missing timing field is noticed');
gf_assert($missing['score'] < 60, 'but does not reach the threshold on its own');

// ─── Names ───────────────────────────────────────────────────────────────────

// Every one of these belongs to somebody. Refusing any of them is a lost sale, and the
// Bengali cases are the ones a rule written for English gets wrong: Bengali writes vowels as
// diacritics, so a vowel test built for Latin text sees none at all.
$real_names = [
    'সাদিয়া ইসলাম',
    'মোঃ রফিকুল ইসলাম',
    'তানজিলা',
    'Md. Rafiqul Islam',
    'Nusrat Jahan',
    'A K M Shamsuddin',
    'Ng',
    'Jyl',                 // short, vowel-less to a naive test, a real name
    'Md Shafiq',
    'রফিক Ahmed',          // the mixture people actually write
    "O'Brien",
    'Al-Amin',
];

foreach ($real_names as $name) {
    gf_assert(!$g->t_mash($name), "a real name is accepted: {$name}");
}

// And these are not names.
$mashes = [
    'aaaa',
    'asdf',
    'asdfgh',
    'qwerty',
    'test',
    'test test',
    'xxxx',
    'ffffff',
    '....',
    'bcdfghjk',            // a long Latin run with no vowel
];

foreach ($mashes as $text) {
    gf_assert($g->t_mash($text), "a keyboard mash is caught: {$text}");
}

// A Bengali address with digits and punctuation must survive.
gf_assert(
    !$g->t_mash('বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা ১২০৯'),
    'a real Bengali address is accepted'
);

// ─── Disposable email ────────────────────────────────────────────────────────

foreach (['a@mailinator.com', 'x@yopmail.com', 'y@10minutemail.com', 'z@TEMP-MAIL.ORG'] as $email) {
    gf_assert($g->t_disposable($email), "a throwaway address is caught: {$email}");
}

foreach (['sadia@gmail.com', 'info@shop.com.bd', 'a@yahoo.com', 'x@outlook.com', 'not-an-email'] as $email) {
    gf_assert(!$g->t_disposable($email), "an ordinary address is accepted: {$email}");
}

// ─── Nothing fires on an empty submission ────────────────────────────────────

// A merchant creating an order by hand in wp-admin posts none of these fields. Scoring that
// as fraud would flag the shop's own orders.
$empty = $g->t_assess([]);
gf_assert($empty['score'] < 60, 'an admin-created order with no checkout fields is not flagged');

// ─── Defaults are safe ───────────────────────────────────────────────────────

// The whole product's stance: a new rule reports for a week before it refuses anything. A
// fraud rule that starts by cancelling real sales is switched off within a day.
$gf_src = file_get_contents(__DIR__ . '/../includes/class-guardify-checkout-guard.php');

gf_assert(
    strpos($gf_src, "get_option('guardify_checkout_guard_dry_run', 'yes')") !== false,
    'the guard starts in dry-run'
);
gf_assert(
    strpos($gf_src, "get_option('guardify_checkout_guard_action', 'flag')") !== false,
    'and flags rather than blocks'
);

// ─── Cost ────────────────────────────────────────────────────────────────────

// This runs on the one page of a shop where slowness costs money directly. The two free
// signals must stay free, and the two that need history must stay off the checkout-refresh
// path — which is the load this plugin has already had to remove from that hook once.
// Matched on the hook being *registered*, not merely mentioned: the class explains in a
// comment why it stays off that hook, and a test that reads its own explanation as the
// offence is a test nobody can satisfy.
gf_assert(
    strpos($gf_src, "add_action('woocommerce_checkout_update_order_review'") === false,
    'the guard does no work on checkout refreshes'
);
gf_assert(
    strpos($gf_src, "add_action('woocommerce_checkout_process', [\$this, 'assess_checkout'], 25)") !== false,
    'assessment runs once, as the order is created'
);
gf_assert(
    strpos($gf_src, 'wp_remote_get') === false && strpos($gf_src, 'wp_remote_post') === false,
    'the guard makes no network request during checkout'
);

// The velocity and duplicate checks are counted in transients rather than queried out of the
// orders table: a COUNT over wc_orders filtered by a meta value, on every order, is the shape
// of query that makes a busy shop slow.
gf_assert(
    strpos($gf_src, '$wpdb') === false,
    'the guard issues no direct database query of its own'
);

gf_test_summary('checkout-guard');
