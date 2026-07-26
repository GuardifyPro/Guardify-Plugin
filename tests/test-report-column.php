<?php
/**
 * Courier verdict rendering tests.
 *
 * The orders list is where a merchant decides whether to send a cash-on-delivery parcel.
 * A wrong or misleading badge here does not produce a bug report — it produces a refused
 * good customer or a lost parcel, and nobody traces either back to the plugin. These
 * assertions are about the card saying what the engine actually found.
 *
 * Run: php tests/test-report-column.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-guardify-format.php';
require_once __DIR__ . '/../includes/class-guardify-report-column.php';

class GF_Column_Probe extends Guardify_Report_Column {
    public static function build() {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
    public function t_render($summary) { return $this->render_summary($summary); }
}

$col = GF_Column_Probe::build();

/** A well-evidenced, reliable customer. */
function gf_good_summary(array $overrides = []) {
    return array_merge([
        'phone'               => '01712345678',
        'total_parcels'       => 40,
        'total_delivered'     => 38,
        'total_cancelled'     => 1,
        'total_returned'      => 1,
        'total_fraud_reports' => 0,
        'dp_ratio'            => 95.0,
        'risk_level'          => 'low',
        'risk' => [
            'score'      => 91,
            'band'       => 'excellent',
            'confidence' => 'high',
            'action'     => 'allow',
        ],
        'providers' => [
            ['provider' => 'steadfast', 'total_parcels' => 30, 'total_delivered' => 29],
            ['provider' => 'redx',      'total_parcels' => 10, 'total_delivered' => 9],
        ],
    ], $overrides);
}

// ─── Bengali formatting ──────────────────────────────────────────────────────

// A shop in Bangladesh reads ৮৭%, not 87%. Mixing the two inside one screen is the
// clearest sign of software that was translated rather than written for this market.
gf_assert_same('৮৭', Guardify_Format::bn('87'), 'digits become Bengali');
gf_assert_same('৯১', Guardify_Format::count(91), 'counts are Bengali');
gf_assert_same('২০%', Guardify_Format::percent(20), 'percentages carry their sign');
gf_assert_same('০', Guardify_Format::count(0), 'zero is ০, not blank');
gf_assert_same('—', Guardify_Format::count('abc'), 'non-numeric input does not render as a number');

// Bengali groups digits the Indian way. A merchant scanning ৳1,23,456 against a Western
// ৳123,456 reads a different magnitude at a glance, which is the whole risk of getting
// grouping wrong on a money figure.
gf_assert_same('৳১,২৩,৪৫৬', Guardify_Format::taka(123456), 'taka uses Indian digit grouping');
gf_assert_same('৳৯৯৯', Guardify_Format::taka(999), 'small amounts are not grouped');
gf_assert_same('৳১,০০০', Guardify_Format::taka(1000), 'the first group breaks at a thousand');
gf_assert(strpos(Guardify_Format::taka(-500), '-') === 0, 'a negative amount keeps its sign');

// ─── The card leads with the score, not the ratio ────────────────────────────

// A ratio cannot tell one parcel from two hundred. A customer who received their only
// order shows 100%; a customer who failed one of three shows 67%. The second is far
// better evidence, and a card that colours on the ratio tells the merchant the opposite.
$html = $col->t_render(gf_good_summary());

gf_assert(strpos($html, 'gf-rc-score') !== false, 'the card shows a score');
gf_assert(strpos($html, '৯১') !== false, 'the score is the engine score, in Bengali digits');
gf_assert(strpos($html, 'চমৎকার') !== false, 'the band is named in Bengali');
gf_assert(strpos($html, 'gf-rc-tone-excellent') !== false, 'the tone follows the band');

// Confidence is stated rather than implied. A score of 60 from two parcels and a score of
// 60 from two hundred are different claims; hiding the difference is how a merchant ends
// up refusing a good customer over one unlucky delivery.
gf_assert(strpos($html, 'যথেষ্ট তথ্য') !== false, 'confidence is spelled out on the card');

// ─── Thin evidence is labelled as thin ───────────────────────────────────────

$thin = $col->t_render(gf_good_summary([
    'total_parcels'   => 2,
    'total_delivered' => 2,
    'providers'       => [],
    'risk' => ['score' => 78, 'band' => 'good', 'confidence' => 'low', 'action' => 'flag'],
]));

gf_assert(strpos($thin, 'অল্প তথ্য') !== false, 'two parcels are labelled as little evidence');
gf_assert(strpos($thin, '১০০%') === false, 'a 2-for-2 customer is not presented as a perfect score');

// The ratio is what merchants look for, so it is shown — but only where it is honest. On
// forty parcels it is a measurement; on two it is a coin toss dressed up as one.
gf_assert(strpos($html, '৯৫%') !== false, 'a well-evidenced customer shows the delivery ratio');
gf_assert(strpos($thin, 'gf-rc-ratio') === false, 'the ratio is withheld below medium confidence');

// ─── No history says so ──────────────────────────────────────────────────────

// With zero parcels the engine returns the national baseline, around 75. Rendering that
// as a score reads as a measurement when it is an assumption, and a merchant acting on it
// is acting on nothing.
$empty = $col->t_render([
    'total_parcels' => 0,
    'risk' => ['score' => 75, 'band' => 'unknown', 'confidence' => 'none', 'action' => 'allow'],
]);

gf_assert(strpos($empty, 'নতুন কাস্টমার') !== false, 'a customer with no history is named as new');
gf_assert(strpos($empty, '৭৫') === false, 'the baseline is not presented as if it were measured');

gf_assert(
    strpos($col->t_render(null), 'নতুন কাস্টমার') !== false,
    'a phone the engine had nothing for renders as new rather than as an error'
);

// ─── Fraud is surfaced on its own ────────────────────────────────────────────

// A courier flagging a customer is a categorically different event from a parcel coming
// back. Averaging the two into one number buries the signal a merchant most wants before
// dispatch — and it is the signal they asked to see.
$fraud = $col->t_render(gf_good_summary([
    'total_fraud_reports' => 3,
    'risk' => ['score' => 32, 'band' => 'high_risk', 'confidence' => 'high', 'action' => 'block'],
]));

gf_assert(strpos($fraud, 'gf-rc-fraud') !== false, 'fraud reports get their own element');
gf_assert(strpos($fraud, '৩ টি ফ্রড রিপোর্ট') !== false, 'the fraud count is shown, in Bengali');
gf_assert(strpos($fraud, 'বাতিল করার পরামর্শ') !== false, 'the block recommendation reaches the merchant');

// A clean customer must not carry a fraud line, or the line stops meaning anything.
gf_assert(strpos($html, 'gf-rc-fraud') === false, 'no fraud line when there are no reports');

// ─── The recommendation appears only when it is not "allow" ──────────────────

// A badge on every safe order is noise, and noise is what stops merchants reading the
// badges that matter.
gf_assert(strpos($html, 'gf-rc-action') === false, 'a safe order carries no recommendation badge');

$advance = $col->t_render(gf_good_summary([
    'risk' => [
        'score' => 52, 'band' => 'caution', 'confidence' => 'medium',
        'action' => 'advance_payment', 'recommended_advance_pct' => 20,
    ],
]));
gf_assert(strpos($advance, 'অগ্রিম ২০% নিন') !== false, 'the advance percentage is named, not just the action');

// An action the plugin does not recognise — a newer engine, an older plugin — must not
// render a raw identifier into the merchant's screen.
$unknown = $col->t_render(gf_good_summary([
    'risk' => ['score' => 50, 'band' => 'caution', 'confidence' => 'medium', 'action' => 'quarantine'],
]));
gf_assert(strpos($unknown, 'quarantine') === false, 'an unrecognised action is not printed raw');

// ─── Per-courier breakdown ───────────────────────────────────────────────────

// Failures concentrated with one courier are a fact about the courier, not the customer,
// and a merchant who can see that ships the parcel with a different one.
gf_assert(strpos($html, 'Steadfast ২৯/৩০') !== false, 'each courier reports its own delivered/total');
gf_assert(strpos($html, 'Redx ৯/১০') !== false, 'every courier with parcels is listed');

// A courier that returned nothing adds a chip saying "0/0", which is not information.
$sparse = $col->t_render(gf_good_summary([
    'providers' => [
        ['provider' => 'steadfast', 'total_parcels' => 40, 'total_delivered' => 38],
        ['provider' => 'pathao',    'total_parcels' => 0,  'total_delivered' => 0],
    ],
]));
gf_assert(strpos($sparse, 'Pathao') === false, 'a courier with no parcels is left off the card');

// ─── The network covers the couriers that have no API ────────────────────────

// Most Bangladeshi couriers publish no phone-history API, so a customer who has taken a
// dozen parcels through one of them is invisible to every courier we can query. Gating
// "new customer" on courier parcels alone throws away the one signal that knows better —
// what every Guardify-connected shop has seen — which is the whole point of the network.
$networkOnly = $col->t_render([
    'total_parcels'   => 0,
    'total_delivered' => 0,
    'risk' => [
        'score' => 88, 'band' => 'good', 'confidence' => 'medium',
        'effective_n' => 12, 'network_stores' => 4, 'action' => 'allow',
    ],
]);

gf_assert(strpos($networkOnly, 'নতুন কাস্টমার') === false,
    'a customer known across the network is not called new');
gf_assert(strpos($networkOnly, '৮৮') !== false, 'the network-informed score is shown');
gf_assert(strpos($networkOnly, '৪ দোকানে রেকর্ড') !== false,
    'the number of network shops is named — it is the one fact only Guardify has');

// One shop is this shop. Saying "recorded at 1 shop" tells the merchant nothing and makes
// the network look emptier than it is.
$oneStore = $col->t_render(gf_good_summary([
    'risk' => ['score' => 91, 'band' => 'excellent', 'confidence' => 'high',
               'effective_n' => 40, 'network_stores' => 1, 'action' => 'allow'],
]));
gf_assert(strpos($oneStore, 'gf-rc-network') === false, 'a single-shop record is not called a network');

// Genuinely nothing anywhere still says so.
$nothing = $col->t_render([
    'total_parcels' => 0,
    'risk' => ['score' => 75, 'band' => 'unknown', 'confidence' => 'none', 'effective_n' => 0],
]);
gf_assert(strpos($nothing, 'নতুন কাস্টমার') !== false, 'zero evidence anywhere is still a new customer');

// ─── A missing courier is disclosed, not hidden ──────────────────────────────

// When a courier is down its parcels simply do not appear, so the customer looks newer and
// less proven than they are — and the score reads worse than the truth. Saying nothing has
// the merchant refuse someone over an outage on our side.
$partial = $col->t_render(gf_good_summary(['partial' => true]));
gf_assert(strpos($partial, 'অসম্পূর্ণ তথ্য') !== false, 'a partial lookup is disclosed on the card');
gf_assert(strpos($html, 'অসম্পূর্ণ তথ্য') === false, 'a complete lookup carries no such warning');

// ─── Malformed input does not break the screen ───────────────────────────────

// The engine and the plugin update independently. A field that arrives missing, null, or
// the wrong type must degrade to a quieter card, never to a PHP warning printed into the
// middle of the merchant's orders table.
$degenerate = [
    ['total_parcels' => 5, 'total_delivered' => 5],                          // no risk block
    ['total_parcels' => 5, 'risk' => null],                                  // null risk
    ['total_parcels' => 5, 'risk' => ['band' => 'martian']],                 // unknown band
    ['total_parcels' => 5, 'providers' => 'not-an-array'],                   // wrong type
    ['total_parcels' => 5, 'providers' => ['bad', ['provider' => 'redx', 'total_parcels' => 2]]],
];
foreach ($degenerate as $i => $input) {
    $out = $col->t_render($input);
    gf_assert(is_string($out) && $out !== '', "malformed summary #{$i} still renders a card");
    gf_assert(strpos($out, 'Warning') === false, "malformed summary #{$i} emits no PHP warning");
}

// An unknown band must fall back to the neutral tone rather than to no tone at all, or
// the card renders unstyled and looks broken.
gf_assert(
    strpos($col->t_render(['total_parcels' => 5, 'risk' => ['band' => 'martian']]), 'gf-rc-tone-unknown') !== false,
    'an unrecognised band falls back to the neutral tone'
);

// ─── Output is escaped ───────────────────────────────────────────────────────

// Provider names come from the engine, which gets them from courier APIs. Nothing in that
// chain is under our control, and the card is rendered into an admin page.
$injected = $col->t_render(gf_good_summary([
    'providers' => [['provider' => '<script>alert(1)</script>', 'total_parcels' => 2, 'total_delivered' => 1]],
]));
gf_assert(strpos($injected, '<script>') === false, 'a provider name cannot inject markup');

gf_test_summary('report-column');
