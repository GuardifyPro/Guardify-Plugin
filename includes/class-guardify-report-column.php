<?php
defined('ABSPATH') || exit;

/**
 * Guardify Report Column — the courier verdict inside the WooCommerce orders list.
 *
 * This is the screen where a merchant decides whether to send a cash-on-delivery parcel,
 * so it is the single most important thing the plugin renders. Everything here follows
 * from that.
 *
 * It shows the risk *score*, not the delivery ratio. A raw ratio cannot tell one parcel
 * from two hundred: a customer who received their only order shows 100% in green, and a
 * customer who failed one of three shows 67% in amber, when the second is by far the
 * better-evidenced. The engine's score is smoothed against a prior and reported with the
 * confidence it deserves, and that is what the merchant sees. The ratio is still shown
 * underneath — merchants recognise it and look for it — but only once there is enough
 * history for it to mean anything, and it no longer drives the colour of anything.
 *
 * Fraud reports get their own line rather than being folded into a number. A courier
 * flagging a customer is a categorically different signal from a failed delivery, and it
 * is the one a merchant most wants to know about before dispatching.
 */
class Guardify_Report_Column {

    private static $instance = null;

    /**
     * Bands, in the order they degrade.
     *
     * A method rather than a const, and not only because PHP will not put a function call in
     * one. Anything evaluated while the class file is being read runs on `plugins_loaded`,
     * and the text domain is loaded on `init` — so a translated string captured at class-load
     * time would be resolved before any catalogue existed and would come out in the source
     * language no matter what the site had installed. Labels have to be built when they are
     * about to be shown.
     */
    public static function bands() {
        return [
            'excellent' => ['label' => __('চমৎকার', 'guardify-pro'), 'tone' => 'excellent'],
            'good'      => ['label' => __('ভালো', 'guardify-pro'), 'tone' => 'good'],
            'caution'   => ['label' => __('সতর্কতা', 'guardify-pro'), 'tone' => 'caution'],
            'high_risk' => ['label' => __('উচ্চ ঝুঁকি', 'guardify-pro'), 'tone' => 'risk'],
            'unknown'   => ['label' => __('তথ্য নেই', 'guardify-pro'), 'tone' => 'unknown'],
        ];
    }

    /** How much evidence the score rests on. */
    public static function confidence_labels() {
        return [
            'high'   => __('যথেষ্ট তথ্য', 'guardify-pro'),
            'medium' => __('মোটামুটি তথ্য', 'guardify-pro'),
            'low'    => __('অল্প তথ্য', 'guardify-pro'),
            'none'   => __('কোনো তথ্য নেই', 'guardify-pro'),
        ];
    }

    /** What the engine recommends doing with the order. */
    public static function action_labels() {
        return [
            'block'           => __('বাতিল করার পরামর্শ', 'guardify-pro'),
            'advance_payment' => __('অগ্রিম নিন', 'guardify-pro'),
            'otp'             => __('OTP যাচাই করুন', 'guardify-pro'),
            'flag'            => __('যাচাই করে পাঠান', 'guardify-pro'),
            'allow'           => __('নিরাপদ', 'guardify-pro'),
        ];
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (get_option('guardify_report_column_enabled', 'yes') !== 'yes') {
            return;
        }

        // CPT orders list
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column']);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_column'], 10, 2);

        // HPOS orders list
        add_filter('woocommerce_shop_order_list_table_columns', [$this, 'add_column']);
        add_action('woocommerce_shop_order_list_table_custom_column', [$this, 'render_column_hpos'], 10, 2);

        add_action('wp_ajax_guardify_fetch_reports', [$this, 'ajax_fetch_reports']);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Add the column after the order total.
     */
    public function add_column($columns) {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_total') {
                $new['gf_report'] = __('কুরিয়ার রিপোর্ট', 'guardify-pro');
            }
        }
        if (!isset($new['gf_report'])) {
            $new['gf_report'] = __('কুরিয়ার রিপোর্ট', 'guardify-pro');
        }
        return $new;
    }

    /** CPT column render. */
    public function render_column($column, $post_id) {
        if ($column !== 'gf_report') {
            return;
        }
        $this->output_container($post_id);
    }

    /** HPOS column render. */
    public function render_column_hpos($column, $order) {
        if ($column !== 'gf_report') {
            return;
        }
        $this->output_container($order->get_id());
    }

    /**
     * Render a placeholder. The data arrives in one batch after the page paints, so the
     * orders list is never held up waiting on a courier API.
     */
    private function output_container($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_billing_phone() === '') {
            echo '<span class="gf-rc-muted">—</span>';
            return;
        }

        echo '<div class="gf-rc-wrap" data-order-id="' . esc_attr($order_id) . '">';
        echo '<div class="gf-rc-loading"></div>';
        echo '</div>';
    }

    /**
     * AJAX: fetch verdicts for every visible order in one call.
     *
     * The previous version fetched one order at a time, three at once. A 20-row page meant
     * twenty signed requests from the merchant's server on every page load — twenty TLS
     * handshakes and twenty PHP workers, on hosting that has few of either. That is the
     * kind of thing that makes a merchant conclude the plugin slowed their site down, and
     * they are not wrong.
     */
    public function ajax_fetch_reports() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $ids = isset($_POST['order_ids']) ? (array) wp_unslash($_POST['order_ids']) : [];
        $ids = array_slice(array_filter(array_map('absint', $ids)), 0, 100);

        if (empty($ids)) {
            wp_send_json_success(['reports' => []]);
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error(__('সংযুক্ত নয়', 'guardify-pro'));
        }

        // Map each order to its normalised phone, keeping the reverse mapping so several
        // orders from the same customer share one lookup instead of paying for three.
        $by_order = [];
        $phones   = [];
        foreach ($ids as $id) {
            $order = wc_get_order($id);
            if (!$order) {
                continue;
            }
            // clean() rather than normalize(): normalize() returns whatever it was given
            // when the input is not a Bangladeshi number, and sending that on would have the
            // engine drop it silently — which the merchant would read as "new customer"
            // rather than "this order has a phone number nobody can deliver to".
            $phone = Guardify_Phone_Util::clean($order->get_billing_phone());
            if ($phone === null) {
                continue;
            }
            $by_order[$id] = $phone;
            $phones[$phone] = true;
        }

        if (empty($phones)) {
            wp_send_json_success(['reports' => []]);
        }

        $result = $api->post('/api/v1/courier/summary/batch', ['phones' => array_keys($phones)]);

        if (!is_array($result) || empty($result['results'])) {
            wp_send_json_error(__('রিপোর্ট পাওয়া যায়নি', 'guardify-pro'));
        }

        $reports = [];
        foreach ($by_order as $id => $phone) {
            $summary = isset($result['results'][$phone]) ? $result['results'][$phone] : null;
            $reports[$id] = $this->render_summary($summary);
        }

        wp_send_json_success(['reports' => $reports]);
    }

    /**
     * Build the card for one customer.
     *
     * @param array|null $summary Engine summary, or null when the lookup produced nothing.
     * @return string
     */
    protected function render_summary($summary) {
        if (!is_array($summary)) {
            return __('<span class="gf-rc-new">নতুন কাস্টমার</span>', 'guardify-pro');
        }

        $risk  = isset($summary['risk']) && is_array($summary['risk']) ? $summary['risk'] : [];
        $total = (int) ($summary['total_parcels'] ?? 0);

        // effective_n, not total_parcels. The score also draws on the Guardify network —
        // what every connected shop has seen of this number — and that covers the couriers
        // with no public history API, which is most of them. A customer with twelve parcels
        // through Paperfly has no courier history we can query and is emphatically not new,
        // and gating on total_parcels alone would throw away the one signal that knows it.
        $evidence = isset($risk['effective_n']) ? (int) $risk['effective_n'] : $total;
        $stores   = isset($risk['network_stores']) ? (int) $risk['network_stores'] : 0;

        // No history anywhere is worth saying plainly. Rendering a score of 75 against zero
        // evidence — which is what the baseline produces — reads as a measurement when it is
        // really an assumption, and a merchant acting on it is acting on nothing.
        if ($evidence === 0) {
            return __('<span class="gf-rc-new">নতুন কাস্টমার</span>', 'guardify-pro');
        }

        $delivered = (int) ($summary['total_delivered'] ?? 0);
        $cancelled = (int) ($summary['total_cancelled'] ?? 0);
        $returned  = (int) ($summary['total_returned'] ?? 0);
        $fraud     = (int) ($summary['total_fraud_reports'] ?? 0);
        $failed    = $cancelled + $returned;

        $score = isset($risk['score']) ? (int) $risk['score'] : null;
        $band  = isset($risk['band']) ? (string) $risk['band'] : 'unknown';
        $conf  = isset($risk['confidence']) ? (string) $risk['confidence'] : 'none';
        $action = isset($risk['action']) ? (string) $risk['action'] : '';

        $bands = self::bands();
        $meta  = isset($bands[$band]) ? $bands[$band] : $bands['unknown'];
        $tone = $meta['tone'];

        $html = '<div class="gf-rc-report gf-rc-tone-' . esc_attr($tone) . '">';

        // Score and band. The score leads because it is the figure that accounts for how
        // much evidence there is; the band is the word a merchant scanning the column reads.
        $html .= '<div class="gf-rc-header">';
        $html .= '<span class="gf-rc-band">' . esc_html($meta['label']) . '</span>';
        $html .= '<span class="gf-rc-score">' . esc_html($score === null ? '—' : Guardify_Format::bn($score)) . '</span>';
        $html .= '</div>';

        $html .= '<div class="gf-rc-bar"><div class="gf-rc-bar-fill" style="width:'
            . esc_attr(max(0, min(100, (int) $score))) . '%"></div></div>';

        // Confidence is stated, not implied. A score of 60 from two parcels and a score of
        // 60 from two hundred are different claims, and hiding the difference is how a
        // merchant ends up refusing a good customer over one unlucky delivery.
        $confidence = self::confidence_labels();
        if (isset($confidence[$conf])) {
            $html .= '<div class="gf-rc-conf">' . esc_html($confidence[$conf]) . '</div>';
        }

        // Fraud gets its own line and its own colour. A courier flagging a customer is not
        // the same event as a parcel coming back, and averaging the two together buries the
        // signal a merchant most wants before dispatch.
        if ($fraud > 0) {
            $html .= '<div class="gf-rc-fraud" title="' . esc_attr__('কুরিয়ার এই নম্বরের বিরুদ্ধে অভিযোগ জমা দিয়েছে', 'guardify-pro') . '">'
                . '<span class="gf-rc-fraud-dot"></span>'
                . esc_html(Guardify_Format::count($fraud)) . __(' টি ফ্রড রিপোর্ট', 'guardify-pro')
                . '</div>';
        }

        // A courier that did not answer means this card is built on less history than the
        // customer actually has, so the score reads worse than the truth. Saying nothing
        // would have the merchant refuse someone over an outage on our side.
        if (!empty($summary['partial'])) {
            $html .= '<div class="gf-rc-partial" title="'
                . esc_attr__('একটি কুরিয়ার সাড়া দেয়নি — কিছু পার্সেল এই হিসাবে নেই', 'guardify-pro')
                . __('">অসম্পূর্ণ তথ্য</div>', 'guardify-pro');
        }

        // The consortium line. This is the part a merchant cannot get anywhere else — a
        // customer unknown to their own shop and to the queryable couriers, but with a
        // record across other Guardify shops — so it is worth stating rather than leaving
        // buried inside a score nobody can decompose.
        if ($stores > 1) {
            $html .= __('<div class="gf-rc-network">Guardify নেটওয়ার্কে ', 'guardify-pro')
                . esc_html(Guardify_Format::count($stores)) . __(' দোকানে রেকর্ড</div>', 'guardify-pro');
        }

        $html .= '<div class="gf-rc-stats">';
        $html .= __('<span>মোট ', 'guardify-pro') . esc_html(Guardify_Format::count($total)) . '</span>';
        $html .= '<span class="gf-rc-divider">·</span>';
        $html .= __('<span class="gf-rc-stat-ok">সফল ', 'guardify-pro') . esc_html(Guardify_Format::count($delivered));

        // The delivery ratio, but only once there is enough history for it to mean
        // something. Merchants recognise this figure and look for it, so it is worth
        // showing — and on two parcels it reads "১০০%", which is the exact misreading the
        // score exists to prevent. Below medium confidence the counts alone are honest;
        // the percentage is not.
        if (in_array($conf, ['medium', 'high'], true) && $total > 0) {
            $html .= ' <span class="gf-rc-ratio">('
                . esc_html(Guardify_Format::percent($delivered / $total * 100)) . ')</span>';
        }
        $html .= '</span>';

        if ($failed > 0) {
            $html .= '<span class="gf-rc-divider">·</span>';
            $html .= __('<span class="gf-rc-stat-fail">ব্যর্থ ', 'guardify-pro') . esc_html(Guardify_Format::count($failed)) . '</span>';
        }
        $html .= '</div>';

        // The recommendation, only when it is not "allow" — a badge on every safe order is
        // noise, and noise is what stops merchants reading the badges that matter.
        $actions = self::action_labels();
        if ($action !== '' && $action !== 'allow' && isset($actions[$action])) {
            $label = $actions[$action];
            if ($action === 'advance_payment' && !empty($risk['recommended_advance_pct'])) {
                $label = __('অগ্রিম ', 'guardify-pro') . Guardify_Format::percent((int) $risk['recommended_advance_pct']) . __(' নিন', 'guardify-pro');
            }
            $html .= '<div class="gf-rc-action gf-rc-action-' . esc_attr($action) . '">' . esc_html($label) . '</div>';
        }

        // Per-courier breakdown, so a merchant can see that the failures are all with one
        // courier — which is a fact about the courier, not about the customer.
        if (!empty($summary['providers']) && is_array($summary['providers'])) {
            $chips = '';
            foreach ($summary['providers'] as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $p_total = (int) ($p['total_parcels'] ?? 0);
                if ($p_total === 0) {
                    continue;
                }
                $p_name = ucfirst((string) ($p['provider'] ?? ''));
                $p_del  = (int) ($p['total_delivered'] ?? 0);
                $chips .= '<span class="gf-rc-prov">' . esc_html($p_name) . ' '
                    . esc_html(Guardify_Format::count($p_del)) . '/' . esc_html(Guardify_Format::count($p_total))
                    . '</span>';
            }
            if ($chips !== '') {
                $html .= '<div class="gf-rc-providers">' . $chips . '</div>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Enqueue assets on the orders screens only.
     */
    public function enqueue_assets($hook) {
        if ('edit.php' !== $hook && 'woocommerce_page_wc-orders' !== $hook) {
            return;
        }
        if ('edit.php' === $hook) {
            $post_type = isset($_GET['post_type']) ? sanitize_key(wp_unslash($_GET['post_type'])) : '';
            if ($post_type !== 'shop_order') {
                return;
            }
        }

        // Its own handle rather than an inline attachment to woocommerce_admin_styles.
        // Attaching to someone else's handle means the styles silently vanish on any screen
        // or WooCommerce build where that handle is not enqueued.
        wp_register_style('guardify-report-column', false, [], GUARDIFY_VERSION);
        wp_enqueue_style('guardify-report-column');
        wp_add_inline_style('guardify-report-column', $this->get_column_css());

        wp_register_script('guardify-report-column', false, ['jquery'], GUARDIFY_VERSION, true);
        wp_enqueue_script('guardify-report-column');
        wp_add_inline_script('guardify-report-column', $this->get_column_js());
    }

    /**
     * One request for the whole page, in chunks of 50 so a merchant showing 200 rows does
     * not send a single request large enough to be worth a timeout.
     */
    private function get_column_js() {
        $nonce = wp_create_nonce('guardify_nonce');

        // The two strings this script can show. Translated here and interpolated into the
        // heredoc, because JavaScript cannot call __() and a heredoc is not a place a PHP
        // tag can be opened. esc_js escapes them for a single-quoted JS literal.
        $t_error   = esc_js(__('ত্রুটি', 'guardify-pro'));
        $t_offline = esc_js(__('সংযোগ ব্যর্থ', 'guardify-pro'));

        return <<<JS
jQuery(function ($) {
    var nonce = '{$nonce}';
    var CHUNK = 50;

    var pending = [];
    \$('.gf-rc-wrap').each(function () { pending.push(\$(this).data('order-id')); });
    if (!pending.length) { return; }

    function fail(ids, message) {
        \$.each(ids, function (_, id) {
            \$('.gf-rc-wrap[data-order-id="' + id + '"]')
                .html('<span class="gf-rc-err">' + message + '</span>');
        });
    }

    function fetch(ids) {
        \$.post(ajaxurl, { action: 'guardify_fetch_reports', order_ids: ids, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { fail(ids, res.data || '{$t_error}'); return; }
                var reports = res.data.reports || {};
                \$.each(ids, function (_, id) {
                    var \$cell = \$('.gf-rc-wrap[data-order-id="' + id + '"]');
                    // An order the engine had nothing for still has to stop spinning, or the
                    // merchant is left watching a placeholder that will never resolve.
                    \$cell.html(reports[id] || '<span class="gf-rc-muted">—</span>');
                });
            })
            .fail(function () { fail(ids, '{$t_offline}'); });
    }

    for (var i = 0; i < pending.length; i += CHUNK) {
        fetch(pending.slice(i, i + CHUNK));
    }
});
JS;
    }

    /**
     * Inline CSS for the column.
     *
     * Colours are stated literally rather than pulled from the plugin's design tokens,
     * because this renders inside WooCommerce's own screen where those tokens are not
     * loaded. They are the same values.
     */
    private function get_column_css() {
        return <<<CSS
/* ─── Guardify courier report column ─────────────────────────── */
.column-gf_report { width: 168px; }
.gf-rc-muted { color: #94a3b8; font-size: 12px; }

.gf-rc-wrap {
    min-width: 140px; padding: 6px 4px;
    display: flex; flex-direction: column;
}

/* Loading shimmer */
.gf-rc-loading {
    width: 100%; height: 5px;
    background: #e2e8f0; border-radius: 3px;
    overflow: hidden; position: relative;
}
.gf-rc-loading::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(90deg, #0f766e, #0b5f5a);
    animation: gf-rc-slide 1.4s ease-in-out infinite;
}
@keyframes gf-rc-slide {
    0%   { transform: translateX(-100%); }
    50%  { transform: translateX(0); }
    100% { transform: translateX(100%); }
}
/* Merchants who set prefers-reduced-motion get a static bar; a grid of twenty of these
   animating at once is exactly the case that motion setting exists for. */
@media (prefers-reduced-motion: reduce) {
    .gf-rc-loading::before { animation: none; opacity: 0.5; }
}

.gf-rc-report { width: 100%; font-size: 12px; }

/* Header: band label + score */
.gf-rc-header {
    display: flex; align-items: baseline; justify-content: space-between;
    gap: 6px; margin-bottom: 4px;
}
.gf-rc-band {
    font-size: 11px; font-weight: 600;
    padding: 2px 8px; border-radius: 9px; white-space: nowrap;
}
.gf-rc-score { font-size: 17px; font-weight: 700; line-height: 1; }

.gf-rc-bar {
    background: #e2e8f0; border-radius: 10px;
    height: 5px; overflow: hidden; margin-bottom: 5px;
}
.gf-rc-bar-fill { height: 100%; border-radius: 10px; }

/* One tone per band drives every colour in the card, so the row reads as a single
   verdict rather than as four independently coloured facts. */
.gf-rc-tone-excellent .gf-rc-band     { background: #dcfce7; color: #15803d; }
.gf-rc-tone-excellent .gf-rc-score    { color: #15803d; }
.gf-rc-tone-excellent .gf-rc-bar-fill { background: #16a34a; }

.gf-rc-tone-good .gf-rc-band     { background: #ecfdf5; color: #047857; }
.gf-rc-tone-good .gf-rc-score    { color: #047857; }
.gf-rc-tone-good .gf-rc-bar-fill { background: #10b981; }

.gf-rc-tone-caution .gf-rc-band     { background: #fef3c7; color: #92400e; }
.gf-rc-tone-caution .gf-rc-score    { color: #b45309; }
.gf-rc-tone-caution .gf-rc-bar-fill { background: #f59e0b; }

.gf-rc-tone-risk .gf-rc-band     { background: #fee2e2; color: #991b1b; }
.gf-rc-tone-risk .gf-rc-score    { color: #b91c1c; }
.gf-rc-tone-risk .gf-rc-bar-fill { background: #ef4444; }

.gf-rc-tone-unknown .gf-rc-band     { background: #f1f5f9; color: #64748b; }
.gf-rc-tone-unknown .gf-rc-score    { color: #64748b; }
.gf-rc-tone-unknown .gf-rc-bar-fill { background: #cbd5e1; }

.gf-rc-conf { font-size: 10px; color: #94a3b8; margin-bottom: 4px; }

/* The network line is the brand colour on purpose: it is the one fact on this card that
   comes from Guardify itself rather than from a courier. */
.gf-rc-network {
    font-size: 10px; font-weight: 500; color: #0b5f5a;
    background: #eaf7f5; border-radius: 4px;
    padding: 2px 6px; margin-bottom: 5px; display: inline-block;
}

/* Amber, not red. A missing courier is a gap in what we know, not a finding about the
   customer, and colouring it like a warning about them would be a lie. */
.gf-rc-partial {
    font-size: 10px; font-weight: 600; color: #92400e;
    background: #fffbeb; border: 1px solid #fde68a;
    border-radius: 5px; padding: 2px 6px; margin-bottom: 5px;
    display: inline-block;
}

/* Fraud reports: their own line, and the only red that appears on an otherwise green
   card, because a courier complaint is a different fact from a failed delivery. */
.gf-rc-fraud {
    display: flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600; color: #b91c1c;
    background: #fef2f2; border: 1px solid #fecaca;
    border-radius: 5px; padding: 3px 7px; margin-bottom: 5px;
}
.gf-rc-fraud-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: #dc2626; flex-shrink: 0;
}

.gf-rc-stats {
    font-size: 11px; color: #64748b;
    display: flex; align-items: center; gap: 4px;
    line-height: 1; white-space: nowrap;
}
.gf-rc-divider  { color: #cbd5e1; }
.gf-rc-stat-ok  { color: #16a34a; font-weight: 500; }
.gf-rc-ratio    { color: #94a3b8; font-weight: 400; }
.gf-rc-stat-fail { color: #ef4444; font-weight: 500; }

/* Recommendation, shown only when it is not "allow". */
.gf-rc-action {
    margin-top: 5px; font-size: 10px; font-weight: 600;
    padding: 3px 7px; border-radius: 5px; text-align: center;
}
.gf-rc-action-block           { background: #fee2e2; color: #991b1b; }
.gf-rc-action-advance_payment { background: #ffedd5; color: #9a3412; }
.gf-rc-action-otp             { background: #e0e7ff; color: #3730a3; }
.gf-rc-action-flag            { background: #fef3c7; color: #92400e; }

.gf-rc-providers {
    margin-top: 5px; padding-top: 5px;
    border-top: 1px solid #e5e7eb;
    display: flex; flex-wrap: wrap; gap: 4px;
}
.gf-rc-prov {
    font-size: 10px; color: #64748b;
    background: #f1f5f9; padding: 1px 5px; border-radius: 3px;
}

.gf-rc-new {
    display: inline-block; font-size: 11px; font-weight: 600;
    color: #0b5f5a; background: #eaf7f5;
    padding: 3px 10px; border-radius: 9px;
}

.gf-rc-err { font-size: 11px; color: #dc2626; }
CSS;
    }
}
