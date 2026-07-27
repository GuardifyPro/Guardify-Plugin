<?php
defined('ABSPATH') || exit;

/**
 * Guardify Checkout Guard — the signals that catch a fake order before it is placed.
 *
 * The rest of the plugin judges a customer by their history: how many parcels a number has
 * received, what other shops have seen. That is the strongest evidence there is, and it is
 * useless against the two cases that cost Bangladeshi shops the most money:
 *
 *   - A number with no history at all, because it was invented ten seconds ago.
 *   - A script placing forty cash-on-delivery orders in a minute, each with a different
 *     invented number, none of which will ever be delivered — but every one of which the
 *     merchant pays a courier to attempt.
 *
 * Neither is a customer with a bad record. They are orders that were never real, and what
 * gives them away is not who placed them but *how* they were placed: filled instantly,
 * submitted from the same browser as the last nine, carrying a name nobody would type.
 *
 * Every signal here is deliberately cheap. The honeypot and the timing check cost nothing at
 * all — no query, no request, no work of any kind on a real customer's checkout. The
 * disposable-domain check is an array lookup. Only two signals touch the database, and only
 * at the moment an order is actually placed, never on the checkout refreshes WooCommerce
 * fires while somebody is typing.
 *
 * Nothing here refuses an order on its own. Each signal has a weight, the weights add up, and
 * what happens at the total is the merchant's setting — which defaults to flagging, in
 * dry-run, because a fraud rule that starts by cancelling real sales gets switched off within
 * a day and never switched back on.
 */
class Guardify_Checkout_Guard {

    private static $instance = null;

    /** Hidden field a bot fills in and a person never sees. */
    const HONEYPOT_FIELD = 'gf_website_url';

    /** Signed timestamp planted when the form renders. */
    const TIMING_FIELD = 'gf_rendered';

    /**
     * Seconds below which a submission was not typed by a human.
     *
     * Chosen low on purpose. A fast customer with autofill can complete a checkout in fifteen
     * seconds; the point is not to measure care, it is to catch a submission that skipped the
     * form entirely. Four seconds is under any plausible human and over any network jitter.
     */
    const MIN_FILL_SECONDS = 4;

    /** How far back a duplicate order counts as the same attempt. */
    const DUPLICATE_WINDOW = 600;

    /** Orders from one address in an hour before it looks automated. */
    const IP_VELOCITY_LIMIT = 5;

    /** Where the per-order verdict is stored, for the orders screen and for support. */
    const ORDER_META = '_guardify_guard';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_enabled()) {
            return;
        }

        // The two free signals need a field in the form.
        add_action('woocommerce_after_order_notes', [$this, 'render_fields']);
        add_action('woocommerce_review_order_before_submit', [$this, 'render_fields']);

        // Assessment happens once, at the point an order is actually being created — not on
        // woocommerce_checkout_update_order_review, which WooCommerce fires on every field
        // change and which is the one place this plugin has already had to remove work from.
        add_action('woocommerce_checkout_process', [$this, 'assess_checkout'], 25);

        add_action('woocommerce_checkout_order_processed', [$this, 'record_verdict'], 5, 1);
    }

    public function is_enabled() {
        return get_option('guardify_checkout_guard_enabled', 'yes') === 'yes';
    }

    /**
     * What the plugin should do when the score crosses the threshold.
     *
     * Same vocabulary as the smart filter, and the same default: flag. A merchant who wants
     * orders refused says so.
     */
    public function action() {
        $action = get_option('guardify_checkout_guard_action', 'flag');

        return in_array($action, ['flag', 'hold', 'block'], true) ? $action : 'flag';
    }

    /** Score at or above which the action applies. */
    public function threshold() {
        return max(10, min(100, (int) get_option('guardify_checkout_guard_threshold', 60)));
    }

    /**
     * Whether findings are recorded without acting on them.
     *
     * On by default. The first thing a merchant should see from a new rule is a week of
     * "these are the orders it would have stopped", not a customer complaining that checkout
     * refused them.
     */
    public function dry_run() {
        return get_option('guardify_checkout_guard_dry_run', 'yes') === 'yes';
    }

    // ─── The form fields ─────────────────────────────────────────────────

    /**
     * Plant the honeypot and the render timestamp.
     *
     * Rendered on two hooks because themes vary in which they keep; both are inside the
     * checkout form, and a guard makes sure the fields are emitted once whichever fires.
     */
    public function render_fields() {
        static $rendered = false;

        if ($rendered) {
            return;
        }
        $rendered = true;

        // Hidden by inline style rather than type="hidden": a bot that skips hidden inputs
        // still fills a text input it can see in the markup, which is the entire trick. It is
        // also removed from the tab order and from screen readers, so a customer using a
        // keyboard or a screen reader never lands on it and never fails the check.
        printf(
            '<div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">'
                . '<label for="%1$s">%2$s</label>'
                . '<input type="text" name="%1$s" id="%1$s" value="" tabindex="-1" autocomplete="off">'
                . '</div>',
            esc_attr(self::HONEYPOT_FIELD),
            esc_html__('এই ঘরটি খালি রাখুন', 'guardify-pro')
        );

        printf(
            '<input type="hidden" name="%s" value="%s">',
            esc_attr(self::TIMING_FIELD),
            esc_attr($this->sign_timestamp(time()))
        );
    }

    /**
     * A timestamp the customer's browser carries and cannot usefully edit.
     *
     * Signed with the site's own auth salt. Without a signature the field is just a number in
     * the page source, and anything automated enough to be worth catching is automated enough
     * to send a different one.
     */
    protected function sign_timestamp($time) {
        return $time . '.' . hash_hmac('sha256', (string) $time, wp_salt('auth'));
    }

    /**
     * Seconds since the form was rendered, or null when the field is missing or forged.
     */
    protected function verify_timestamp($value) {
        if (!is_string($value) || strpos($value, '.') === false) {
            return null;
        }

        list($time, $signature) = explode('.', $value, 2);

        if (!ctype_digit($time)) {
            return null;
        }

        $expected = hash_hmac('sha256', $time, wp_salt('auth'));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        return max(0, time() - (int) $time);
    }

    // ─── Assessment ──────────────────────────────────────────────────────

    /**
     * Score this submission and act on it, once, as the order is being created.
     */
    public function assess_checkout() {
        $assessment = $this->assess($_POST);

        // Stashed for record_verdict(), which runs after the order row exists. Recomputing
        // there is not an option: the honeypot and timing fields are request data, and by
        // then they are the only evidence that any of this happened.
        $GLOBALS['guardify_guard_assessment'] = $assessment;

        if ($assessment['score'] < $this->threshold()) {
            return;
        }

        if ($this->dry_run()) {
            return;
        }

        if ($this->action() === 'block') {
            // Deliberately vague. A message naming the signal that caught them is a message
            // that tells whoever is testing the shop exactly what to change.
            wc_add_notice(
                __('এই অর্ডারটি সম্পন্ন করা যাচ্ছে না। সমস্যা মনে হলে দোকানে যোগাযোগ করুন।', 'guardify-pro'),
                'error'
            );
        }
    }

    /**
     * Score one submission.
     *
     * Public and pure so it can be tested without a checkout: it takes the posted fields and
     * returns the verdict, touching the database only for the two signals that need history.
     *
     * @param array $post
     * @return array{score:int, reasons:array, signals:array}
     */
    public function assess(array $post) {
        $score   = 0;
        $reasons = [];
        $signals = [];

        // ── The honeypot ──────────────────────────────────────────────────
        //
        // Weighted to the threshold on its own. A human cannot fill a field positioned off
        // the canvas, removed from the tab order and hidden from screen readers — so there is
        // no innocent explanation, and unlike every other signal here it has no false
        // positive to trade off.
        $honeypot = isset($post[self::HONEYPOT_FIELD]) ? trim((string) $post[self::HONEYPOT_FIELD]) : '';
        if ($honeypot !== '') {
            $score += 100;
            $signals[] = 'honeypot';
            $reasons[] = __('লুকানো ঘরটি পূরণ করা হয়েছে — এটি স্বয়ংক্রিয় সাবমিশন।', 'guardify-pro');
        }

        // ── How long the form was open ────────────────────────────────────
        $elapsed = $this->verify_timestamp(
            isset($post[self::TIMING_FIELD]) ? (string) $post[self::TIMING_FIELD] : ''
        );

        if ($elapsed === null) {
            // Missing or forged. Weighted lightly rather than treated as proof: a cached
            // checkout page, an aggressive optimisation plugin stripping hidden inputs, or a
            // theme that rebuilds the form can all lose the field on a real customer.
            $score += 20;
            $signals[] = 'timing_missing';
            $reasons[] = __('ফর্মের সময় যাচাই করা যায়নি।', 'guardify-pro');
        } elseif ($elapsed < self::MIN_FILL_SECONDS) {
            $score += 60;
            $signals[] = 'too_fast';
            $reasons[] = sprintf(
                /* translators: %d is a number of seconds */
                __('চেকআউট মাত্র %d সেকেন্ডে সম্পন্ন হয়েছে — কেউ টাইপ করেনি।', 'guardify-pro'),
                (int) $elapsed
            );
        }

        // ── What was typed ────────────────────────────────────────────────
        $name = trim(
            (isset($post['billing_first_name']) ? (string) $post['billing_first_name'] : '') . ' ' .
            (isset($post['billing_last_name']) ? (string) $post['billing_last_name'] : '')
        );

        if ($name !== '' && $this->looks_like_keyboard_mash($name)) {
            $score += 35;
            $signals[] = 'gibberish_name';
            $reasons[] = __('নামটি বাস্তব মনে হচ্ছে না।', 'guardify-pro');
        }

        $address = isset($post['billing_address_1']) ? trim((string) $post['billing_address_1']) : '';
        if ($address !== '' && $this->looks_like_keyboard_mash($address)) {
            $score += 25;
            $signals[] = 'gibberish_address';
            $reasons[] = __('ঠিকানাটি বাস্তব মনে হচ্ছে না।', 'guardify-pro');
        }

        $email = isset($post['billing_email']) ? trim((string) $post['billing_email']) : '';
        if ($email !== '' && $this->is_disposable_email($email)) {
            $score += 30;
            $signals[] = 'disposable_email';
            $reasons[] = __('ইমেইলটি একটি সাময়িক (disposable) ঠিকানা।', 'guardify-pro');
        }

        /**
         * Filter the checkout guard's assessment before the history signals are added.
         *
         * For a shop that knows something about its own customers — a wholesaler whose buyers
         * legitimately place ten orders an hour, say.
         *
         * @param array $assessment
         * @param array $post
         */
        $assessment = apply_filters('guardify_checkout_guard_assess', [
            'score'   => $score,
            'reasons' => $reasons,
            'signals' => $signals,
        ], $post);

        return $assessment;
    }

    /**
     * Whether a string looks typed rather than mashed.
     *
     * Deliberately conservative, and it has to be: this runs on the names of real customers
     * in Bengali, in English, and in the mixture of the two that people actually write. A
     * rule that is even slightly too eager here refuses a genuine sale, which costs more than
     * the fake order it caught.
     *
     * So it only fires on things no name contains: a run of one character, a long stretch of
     * Latin letters with no vowel in it, or one of the placeholder words somebody types when
     * they are testing a checkout rather than buying from it.
     *
     * Non-Latin scripts are exempt from the vowel rule entirely. Bengali marks vowels with
     * diacritics rather than separate letters, so "সাদিয়া" has no character this test would
     * count and would be refused by a rule written for English.
     */
    protected function looks_like_keyboard_mash($text) {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) < 3) {
            return false;
        }

        // The same character four times over: "aaaa", "....", "1111".
        if (preg_match('/(.)\1{3,}/u', $text)) {
            return true;
        }

        $lower = mb_strtolower($text, 'UTF-8');

        // What people type when they are trying a shop rather than buying from it.
        $placeholders = ['test', 'testing', 'asdf', 'asdfgh', 'qwerty', 'abcd', 'xxxx', 'aaaa', 'na', 'n/a'];
        foreach ($placeholders as $word) {
            if ($lower === $word || $lower === $word . ' ' . $word) {
                return true;
            }
        }

        /**
         * Filter the placeholder words treated as not-a-real-name.
         *
         * @param string[] $placeholders
         */
        $placeholders = (array) apply_filters('guardify_guard_placeholder_names', $placeholders);
        if (in_array($lower, $placeholders, true)) {
            return true;
        }

        // The vowel test applies only to text that is entirely Latin letters and spaces.
        // Anything else — Bengali, Arabic, a mixture — is left alone.
        if (!preg_match('/^[a-z\s.\'-]+$/', $lower)) {
            return false;
        }

        $letters = preg_replace('/[^a-z]/', '', $lower);
        if (strlen($letters) >= 6 && !preg_match('/[aeiouy]/', $letters)) {
            return true;
        }

        return false;
    }

    /**
     * Whether an email is from a throwaway provider.
     *
     * A short list rather than a live API. Checking a remote service would put a network call
     * on the checkout path, which this plugin has already had to take out of it once; and the
     * signal is worth 30 points, not worth a customer waiting.
     */
    protected function is_disposable_email($email) {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = strtolower(substr($email, $at + 1));

        $disposable = [
            'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
            '10minutemail.com', 'tempmail.com', 'temp-mail.org', 'throwawaymail.com',
            'yopmail.com', 'trashmail.com', 'getnada.com', 'dispostable.com',
            'maildrop.cc', 'fakeinbox.com', 'mailnesia.com', 'tempr.email',
            'discard.email', 'mailcatch.com', 'moakt.com', 'emailondeck.com',
        ];

        /**
         * Filter the disposable email domains.
         *
         * @param string[] $disposable
         */
        $disposable = (array) apply_filters('guardify_disposable_email_domains', $disposable);

        return in_array($domain, $disposable, true);
    }

    // ─── Recording ───────────────────────────────────────────────────────

    /**
     * Attach the verdict to the order.
     *
     * Written whatever the score, including zero. An order with no findings recorded is how a
     * merchant can tell the guard looked at it and found nothing, as against not having been
     * running at all — which is the question they will ask the first time one gets through.
     */
    public function record_verdict($order_id) {
        $assessment = isset($GLOBALS['guardify_guard_assessment'])
            ? $GLOBALS['guardify_guard_assessment']
            : null;

        unset($GLOBALS['guardify_guard_assessment']);

        if (!is_array($assessment)) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Both history signals live here rather than in assess(), so the two queries they
        // cost are paid once per completed order — not on the checkout refreshes WooCommerce
        // fires while a customer is still filling the form in.
        $assessment = $this->add_history_signals($assessment, $order);

        $order->update_meta_data(self::ORDER_META, [
            'score'   => (int) $assessment['score'],
            'signals' => array_values((array) $assessment['signals']),
            'dry_run' => $this->dry_run(),
            'at'      => time(),
        ]);

        if ($assessment['score'] >= $this->threshold() && !empty($assessment['reasons'])) {
            $order->add_order_note(
                __('Guardify চেকআউট গার্ড:', 'guardify-pro') . ' ' .
                implode(' ', array_map('strval', $assessment['reasons']))
            );

            if (!$this->dry_run() && $this->action() === 'hold') {
                $order->update_status(
                    'on-hold',
                    __('Guardify: যাচাই করে ছাড়ুন।', 'guardify-pro')
                );
            }
        }

        $order->save();
    }

    /**
     * The two signals that need to look at other orders.
     */
    protected function add_history_signals(array $assessment, $order) {
        $phone = method_exists($order, 'get_billing_phone') ? $order->get_billing_phone() : '';

        // ── The same cart, from the same number, minutes ago ──────────────
        //
        // A customer who double-taps "place order" produces this, and so does a script
        // replaying one request. Weighted below the threshold on its own, because the
        // innocent explanation is common and costs the merchant only a duplicate.
        if ($phone !== '' && $this->has_recent_duplicate($order, $phone)) {
            $assessment['score']    += 30;
            $assessment['signals'][] = 'duplicate_order';
            $assessment['reasons'][] = __('একই নম্বর থেকে কিছুক্ষণ আগে প্রায় একই অর্ডার এসেছে।', 'guardify-pro');
        }

        // ── How many orders this address has placed in an hour ────────────
        $ip = class_exists('Guardify_Client_IP') ? Guardify_Client_IP::get() : '';
        if ($ip !== '') {
            $recent = $this->orders_from_ip($ip);
            if ($recent > self::IP_VELOCITY_LIMIT) {
                $assessment['score']    += 40;
                $assessment['signals'][] = 'ip_velocity';
                $assessment['reasons'][] = sprintf(
                    /* translators: %d is a number of orders */
                    __('এক ঘণ্টায় একই সংযোগ থেকে %dটি অর্ডার এসেছে।', 'guardify-pro'),
                    (int) $recent
                );
            }
        }

        return $assessment;
    }

    /**
     * Whether a near-identical order arrived from this number in the last few minutes.
     */
    protected function has_recent_duplicate($order, $phone) {
        $fingerprint = $this->cart_fingerprint($order);
        if ($fingerprint === '') {
            return false;
        }

        $seen = get_transient('gf_cartfp_' . md5($phone . '|' . $fingerprint));

        // Recorded for the next attempt whether or not this one matched, so the window
        // measures from the previous order rather than from the first ever.
        set_transient('gf_cartfp_' . md5($phone . '|' . $fingerprint), 1, self::DUPLICATE_WINDOW);

        return $seen !== false;
    }

    /**
     * A stable description of what was ordered: product ids and quantities, sorted.
     */
    protected function cart_fingerprint($order) {
        if (!method_exists($order, 'get_items')) {
            return '';
        }

        $parts = [];
        foreach ($order->get_items() as $item) {
            $parts[] = $item->get_product_id() . 'x' . $item->get_quantity();
        }

        if (empty($parts)) {
            return '';
        }

        sort($parts);

        return implode(',', $parts);
    }

    /**
     * How many orders this address has placed in the last hour, counted from a transient
     * rather than the orders table.
     *
     * A COUNT over wc_orders filtered by a meta value is an expensive query on a busy shop,
     * and this runs on every order. A counter that expires by itself gives the same answer
     * for the same cost as reading one option.
     */
    protected function orders_from_ip($ip) {
        $key   = 'gf_ipv_' . md5($ip);
        $count = (int) get_transient($key);
        $count++;

        set_transient($key, $count, HOUR_IN_SECONDS);

        return $count;
    }
}
