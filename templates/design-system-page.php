<?php
/**
 * Guardify Pro — Design system reference.
 *
 * Every component in admin.css, rendered once, with the class names beside it.
 * A design system that is only visible inside real pages rots: a component
 * nobody is currently using breaks silently and is found months later by a
 * merchant. This page is the place where breakage is visible immediately.
 *
 * Registered as a submenu only when WP_DEBUG is on — see register_menu() in
 * guardify-pro.php.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

/**
 * Section wrapper for one component family.
 *
 * @param string $title Component name.
 * @param string $classes Class names a developer would copy.
 */
if (!function_exists('guardify_ds_section_open')) :
function guardify_ds_section_open($title, $classes = '') {
    ?>
    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php echo esc_html($title); ?></h2>
                <?php if ($classes !== '') : ?>
                <p class="gf-card-desc"><code><?php echo esc_html($classes); ?></code></p>
                <?php endif; ?>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
    <?php
}

function guardify_ds_section_close() {
    echo '</div></div>';
}
endif;

$ds_tokens = [
    'primary'     => 'var(--gf-primary)',
    'accent'      => 'var(--gf-accent)',
    'fg'          => 'var(--gf-fg)',
    'muted-fg'    => 'var(--gf-muted-fg)',
    'border'      => 'var(--gf-border)',
    'success'     => 'var(--gf-success)',
    'warning'     => 'var(--gf-warning)',
    'destructive' => 'var(--gf-destructive)',
    'info'        => 'var(--gf-info)',
];

$ds_risk = [
    ['band' => 'low',     'label' => 'কম ঝুঁকি',      'score' => 94, 'conf' => '৪১টি পার্সেলের ভিত্তিতে'],
    ['band' => 'medium',  'label' => 'মাঝারি ঝুঁকি',  'score' => 62, 'conf' => '১৮টি পার্সেলের ভিত্তিতে'],
    ['band' => 'high',    'label' => 'উচ্চ ঝুঁকি',    'score' => 21, 'conf' => '২৭টি পার্সেলের ভিত্তিতে'],
    ['band' => 'unknown', 'label' => 'তথ্য নেই',      'score' => 0,  'conf' => 'কোনো কুরিয়ার রেকর্ড নেই'],
];
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">G</div>
            <div>
                <h1 class="gf-page-title">ডিজাইন সিস্টেম</h1>
                <p class="gf-page-desc">
                    admin.css-এর সব কম্পোনেন্ট এক জায়গায়। এই পেজ শুধু <code>WP_DEBUG</code> চালু থাকলে দেখা যায়।
                </p>
            </div>
        </div>
        <div class="gf-page-header-actions">
            <span class="gf-badge gf-badge-warning gf-badge-dot">ডেভেলপার টুল</span>
            <span class="gf-badge gf-badge-muted">v<?php echo esc_html(GUARDIFY_VERSION); ?></span>
        </div>
    </div>

    <!-- ── Tokens ─────────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('কালার টোকেন', '--gf-*'); ?>
        <p class="gf-help">
            guardify.pro-র সাথে একই jade-teal স্কেল। লাল, অ্যাম্বার ও সবুজ শুধু ঝুঁকির অর্থ বহন করে — ব্র্যান্ড রঙ কখনো ওই তিনটির সাথে প্রতিযোগিতা করবে না।
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:0.75rem;">
            <?php foreach ($ds_tokens as $name => $value) : ?>
            <div>
                <div style="height:48px;border-radius:var(--gf-radius);border:1px solid var(--gf-border);background:<?php echo esc_attr($value); ?>;"></div>
                <p class="gf-text-xs gf-mt-1"><code><?php echo esc_html($name); ?></code></p>
            </div>
            <?php endforeach; ?>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Typography ─────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('টাইপোগ্রাফি', '.gf-page-title .gf-card-title .gf-help .gf-text-*'); ?>
        <div>
            <p class="gf-page-title">পেজ টাইটেল — অর্ডার যাচাই</p>
            <p class="gf-card-title">কার্ড টাইটেল — ব্লক করা ফোন নম্বর</p>
            <p>বডি টেক্সট — গ্রাহকের ডেলিভারি রেকর্ড দেখে সিদ্ধান্ত নিন। Latin body text 0123456789.</p>
            <p class="gf-help">হেল্প টেক্সট — এই সেটিং কী করে এবং কখন দরকার তা এখানে লেখা থাকে।</p>
            <p class="gf-text-xs gf-text-muted">Extra small muted — ২৪ ঘণ্টা আগে</p>
            <p class="gf-mono gf-text-sm gf-mt-1">মনোস্পেস: 01712345678 · gp_a1b2c3d4</p>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Risk score ─────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('রিস্ক স্কোর', '.gf-risk .gf-risk-dial .gf-risk-band .gf-risk-confidence'); ?>
        <p class="gf-help">
            পণ্যের কেন্দ্রীয় ভিজ্যুয়াল। স্কোর, ব্যান্ড ও কতটি পার্সেলের ভিত্তিতে — তিনটি একসাথে, কারণ ২টি পার্সেলের ২০% আর ২০০টি পার্সেলের ২০% এক সিদ্ধান্ত নয়।
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0.875rem;">
            <?php foreach ($ds_risk as $r) : ?>
            <div class="gf-risk gf-risk-<?php echo esc_attr($r['band']); ?>">
                <div class="gf-risk-dial" style="--gf-risk-pct: <?php echo esc_attr($r['score']); ?>;">
                    <span class="gf-risk-score"><?php echo esc_html($r['score']); ?><small>%</small></span>
                </div>
                <div class="gf-risk-meta">
                    <span class="gf-risk-band"><?php echo esc_html($r['label']); ?></span>
                    <span class="gf-risk-label">ডেলিভারি সাকসেস রেট</span>
                    <span class="gf-risk-confidence"><?php echo esc_html($r['conf']); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="gf-section">
            <h3 class="gf-section-title">টেবিলের ভেতরের রূপ</h3>
            <p class="gf-section-desc"><code>.gf-risk-badge</code> — যেখানে ডায়াল বসানোর জায়গা নেই।</p>
            <div class="gf-section-body gf-row">
                <span class="gf-risk-badge gf-risk-low">৯৪% · কম</span>
                <span class="gf-risk-badge gf-risk-medium">৬২% · মাঝারি</span>
                <span class="gf-risk-badge gf-risk-high">২১% · উচ্চ</span>
                <span class="gf-risk-badge gf-risk-unknown">তথ্য নেই</span>
            </div>
        </div>

        <div class="gf-section">
            <h3 class="gf-section-title">DP বার</h3>
            <p class="gf-section-desc"><code>.gf-dp-bar</code> / <code>.gf-dp-bar-fill</code> — অর্ডার লিস্টের কলামে।</p>
            <div class="gf-section-body" style="max-width:260px;">
                <div class="gf-dp-bar"><div class="gf-dp-bar-fill gf-dp-fill-high" style="width:92%;"></div></div>
                <div class="gf-dp-bar"><div class="gf-dp-bar-fill gf-dp-fill-medium" style="width:58%;"></div></div>
                <div class="gf-dp-bar"><div class="gf-dp-bar-fill gf-dp-fill-low" style="width:22%;"></div></div>
            </div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Buttons ────────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('বাটন', '.gf-btn .gf-btn-primary|secondary|outline|ghost|danger .gf-btn-sm|lg'); ?>
        <div class="gf-row">
            <button type="button" class="gf-btn gf-btn-primary">প্রাইমারি</button>
            <button type="button" class="gf-btn gf-btn-secondary">সেকেন্ডারি</button>
            <button type="button" class="gf-btn gf-btn-outline">আউটলাইন</button>
            <button type="button" class="gf-btn gf-btn-ghost">ঘোস্ট</button>
            <button type="button" class="gf-btn gf-btn-danger">ডেঞ্জার</button>
            <button type="button" class="gf-btn gf-btn-link">লিংক বাটন</button>
        </div>

        <div class="gf-row">
            <button type="button" class="gf-btn gf-btn-primary gf-btn-sm">ছোট</button>
            <button type="button" class="gf-btn gf-btn-primary">ডিফল্ট</button>
            <button type="button" class="gf-btn gf-btn-primary gf-btn-lg">বড়</button>
            <button type="button" class="gf-btn gf-btn-secondary gf-btn-icon" aria-label="রিফ্রেশ">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            </button>
        </div>

        <div class="gf-row">
            <button type="button" class="gf-btn gf-btn-primary is-loading">সংরক্ষণ হচ্ছে</button>
            <button type="button" class="gf-btn gf-btn-secondary is-loading">লোড হচ্ছে</button>
            <button type="button" class="gf-btn gf-btn-primary" disabled>ডিজেবলড</button>
            <button type="button" class="gf-btn gf-btn-secondary" disabled>ডিজেবলড</button>
        </div>

        <div class="gf-row">
            <button type="button" class="gf-icon-btn" aria-label="এডিট">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4z"/></svg>
            </button>
            <button type="button" class="gf-icon-btn gf-icon-btn-success" aria-label="SMS">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </button>
            <button type="button" class="gf-icon-btn gf-icon-btn-info" aria-label="কল">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8.1 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/></svg>
            </button>
            <button type="button" class="gf-icon-btn gf-icon-btn-danger" aria-label="মুছুন">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
            </button>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Badges ─────────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('ব্যাজ ও পিল', '.gf-badge .gf-count-pill .gf-kbd .gf-delivery-badge'); ?>
        <div class="gf-row">
            <span class="gf-badge">ডিফল্ট</span>
            <span class="gf-badge gf-badge-primary">প্রাইমারি</span>
            <span class="gf-badge gf-badge-success gf-badge-dot">সংযুক্ত</span>
            <span class="gf-badge gf-badge-warning">পেন্ডিং</span>
            <span class="gf-badge gf-badge-danger gf-badge-dot">ব্লকড</span>
            <span class="gf-badge gf-badge-info">তথ্য</span>
            <span class="gf-badge gf-badge-muted">নিষ্ক্রিয়</span>
            <span class="gf-badge gf-badge-secondary">সেকেন্ডারি</span>
            <span class="gf-badge gf-badge-outline">আউটলাইন</span>
        </div>
        <div class="gf-row">
            <span class="gf-count-pill">১২</span>
            <span class="gf-count-pill gf-count-pill-muted">৩</span>
            <span class="gf-kbd">Esc</span>
            <span class="gf-kbd">⌘K</span>
        </div>
        <div class="gf-row">
            <span class="gf-delivery-badge gf-delivery-success">৯২% ডেলিভার্ড</span>
            <span class="gf-delivery-badge gf-delivery-warning">৫৮% ডেলিভার্ড</span>
            <span class="gf-delivery-badge gf-delivery-danger">২২% ডেলিভার্ড</span>
            <span class="gf-delivery-badge gf-delivery-muted">রেকর্ড নেই</span>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Stat tiles ─────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('স্ট্যাট টাইল', '.gf-stats-grid .gf-stat-card .gf-stat-icon .gf-stat-delta'); ?>
        <div class="gf-stats-grid" style="margin-bottom:0;">
            <div class="gf-stat-card">
                <div class="gf-stat-icon gf-stat-icon-primary" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/></svg>
                </div>
                <div class="gf-stat-body">
                    <p class="gf-stat-label">মোট অর্ডার</p>
                    <p class="gf-stat-value">১২,৪৮৩</p>
                    <span class="gf-stat-delta gf-stat-delta-up">↑ ৮.৪%</span>
                </div>
            </div>
            <div class="gf-stat-card">
                <div class="gf-stat-icon gf-stat-icon-success" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
                </div>
                <div class="gf-stat-body">
                    <p class="gf-stat-label">ডেলিভার্ড</p>
                    <p class="gf-stat-value">৯,২১০</p>
                </div>
            </div>
            <div class="gf-stat-card">
                <div class="gf-stat-icon gf-stat-icon-danger" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
                </div>
                <div class="gf-stat-body">
                    <p class="gf-stat-label">রিটার্ন</p>
                    <p class="gf-stat-value">৮৪৩</p>
                    <span class="gf-stat-delta gf-stat-delta-down">↓ ২.১%</span>
                </div>
            </div>
            <div class="gf-stat-card">
                <div class="gf-stat-icon gf-stat-icon-warning" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
                </div>
                <div class="gf-stat-body">
                    <p class="gf-stat-label">পেন্ডিং</p>
                    <p class="gf-stat-value">৩১</p>
                    <span class="gf-stat-delta gf-stat-delta-flat">— অপরিবর্তিত</span>
                </div>
            </div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Forms ──────────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('ফর্ম ফিল্ড', '.gf-field .gf-label .gf-input .gf-select .gf-help .gf-error-text'); ?>
        <div class="gf-field-row">
            <div class="gf-field">
                <label class="gf-label" for="ds-text">ফোন নম্বর <span class="gf-required">*</span></label>
                <input type="text" id="ds-text" class="gf-input" placeholder="01XXXXXXXXX" />
                <span class="gf-help">১১ সংখ্যার বাংলাদেশি নম্বর, ০ দিয়ে শুরু।</span>
            </div>
            <div class="gf-field">
                <label class="gf-label" for="ds-select">অ্যাকশন</label>
                <select id="ds-select" class="gf-select">
                    <option>ব্লক করুন</option>
                    <option>OTP ভেরিফিকেশন</option>
                    <option>ফ্ল্যাগ করুন</option>
                </select>
                <span class="gf-help">নিশ্চিত না হলে ফ্ল্যাগ বেছে নিন।</span>
            </div>
        </div>

        <div class="gf-field-row">
            <div class="gf-field">
                <label class="gf-label" for="ds-invalid">ভুল অবস্থা</label>
                <input type="text" id="ds-invalid" class="gf-input is-invalid" value="0171234" aria-invalid="true" />
                <p class="gf-error-text">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
                    নম্বরটি ১১ সংখ্যার নয়।
                </p>
            </div>
            <div class="gf-field">
                <label class="gf-label" for="ds-disabled">ডিজেবলড</label>
                <input type="text" id="ds-disabled" class="gf-input" value="পরিবর্তন করা যাবে না" disabled />
            </div>
        </div>

        <div class="gf-field gf-field-wide">
            <label class="gf-label" for="ds-textarea">SMS টেমপ্লেট</label>
            <textarea id="ds-textarea" class="gf-input" rows="3">আসসালামু আলাইকুম {customer_name}, আপনার অর্ডার #{order_number} নিশ্চিত হয়েছে।</textarea>
            <span class="gf-help">বাংলা লেখা বেশি জায়গা নেয় — লম্বা মেসেজ একাধিক SMS হিসেবে গোনা হয়।</span>
        </div>

        <div class="gf-field gf-field-mid">
            <label class="gf-label" for="ds-copy">কপি-টু-ক্লিপবোর্ড</label>
            <div class="gf-copy">
                <input type="text" id="ds-copy" class="gf-copy-value" value="gp_4f9a2c81be7d0356" readonly />
                <button type="button" class="gf-copy-btn" data-gf-copy="#ds-copy">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h8"/></svg>
                    <span data-gf-copy-label>কপি</span>
                </button>
            </div>
            <span class="gf-help"><code>.gf-copy</code> — বাটনে ক্লিক করে দেখুন।</span>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Toggles, checkboxes, radios ────────────────────────────────────── -->
    <?php guardify_ds_section_open('টগল, চেকবক্স ও রেডিও', '.gf-switch .gf-toggle-row .gf-check .gf-radio'); ?>
        <div class="gf-settings-list">
            <label class="gf-toggle-row">
                <span class="gf-toggle-info">
                    <span class="gf-toggle-label">স্মার্ট অর্ডার ফিল্টার</span>
                    <span class="gf-toggle-desc">DP রেশিও দেখে ঝুঁকিপূর্ণ অর্ডার ব্লক, OTP বা ফ্ল্যাগ করে।</span>
                </span>
                <span class="gf-switch">
                    <input type="checkbox" checked />
                    <span class="gf-switch-slider"></span>
                </span>
            </label>
            <label class="gf-toggle-row">
                <span class="gf-toggle-info">
                    <span class="gf-toggle-label">VPN / প্রক্সি ব্লক</span>
                    <span class="gf-toggle-desc">VPN-এর পেছন থেকে আসা চেকআউট আটকায়।</span>
                </span>
                <span class="gf-switch">
                    <input type="checkbox" />
                    <span class="gf-switch-slider"></span>
                </span>
            </label>
            <label class="gf-toggle-row">
                <span class="gf-toggle-info">
                    <span class="gf-toggle-label">ডিজেবলড টগল</span>
                    <span class="gf-toggle-desc">সাবস্ক্রিপশন ছাড়া পরিবর্তন করা যাবে না।</span>
                </span>
                <span class="gf-switch">
                    <input type="checkbox" checked disabled />
                    <span class="gf-switch-slider"></span>
                </span>
            </label>
        </div>

        <div class="gf-section">
            <h3 class="gf-section-title">চেকবক্স গ্রিড</h3>
            <div class="gf-section-body">
                <div class="gf-checkbox-grid">
                    <label class="gf-checkbox-item"><input type="checkbox" class="gf-check" checked /><span>Processing</span></label>
                    <label class="gf-checkbox-item"><input type="checkbox" class="gf-check" checked /><span>Completed</span></label>
                    <label class="gf-checkbox-item"><input type="checkbox" class="gf-check" /><span>On hold</span></label>
                    <label class="gf-checkbox-item"><input type="checkbox" class="gf-check" /><span>Cancelled</span></label>
                    <label class="gf-checkbox-item"><input type="checkbox" class="gf-check" disabled /><span>Refunded</span></label>
                </div>
            </div>
        </div>

        <div class="gf-section">
            <h3 class="gf-section-title">রেডিও</h3>
            <div class="gf-section-body">
                <label class="gf-check-row"><input type="radio" name="ds-radio" class="gf-radio" checked /><span>Steadfast</span></label>
                <label class="gf-check-row"><input type="radio" name="ds-radio" class="gf-radio" /><span>Pathao</span></label>
                <label class="gf-check-row"><input type="radio" name="ds-radio" class="gf-radio" disabled /><span>RedX (শীঘ্রই)</span></label>
            </div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Table ──────────────────────────────────────────────────────────── -->
    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">
                    ডেটা টেবিল
                    <span class="gf-count-pill">৪</span>
                </h2>
                <p class="gf-card-desc"><code>.gf-table .gf-table-stack</code> — উইন্ডো ৭৮২px-এর নিচে নামালে প্রতিটি সারি আলাদা কার্ড হয়ে যায়।</p>
            </div>
            <div class="gf-card-header-actions">
                <button type="button" class="gf-btn gf-btn-secondary gf-btn-sm">এক্সপোর্ট</button>
                <button type="button" class="gf-btn gf-btn-primary gf-btn-sm">যোগ করুন</button>
            </div>
        </div>
        <div class="gf-card-body gf-flush">
            <div class="gf-table-wrap">
                <table class="gf-table gf-table-stack">
                    <thead>
                        <tr>
                            <th class="gf-col-check" data-label=""><input type="checkbox" class="gf-check" aria-label="সব সিলেক্ট" /></th>
                            <th>ফোন</th>
                            <th>নাম</th>
                            <th>ঝুঁকি</th>
                            <th class="gf-table-num">অর্ডার</th>
                            <th>স্ট্যাটাস</th>
                            <th class="gf-col-action" data-label="">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $ds_rows = [
                            ['01712345678', 'রাশেদ করিম',   'low',     '৯৪%',   12, 'success', 'সক্রিয়'],
                            ['01898765432', 'নুসরাত জাহান', 'medium',  '৬২%',   5,  'warning', 'পর্যবেক্ষণে'],
                            ['01555000111', 'অজানা',        'high',    '২১%',   9,  'danger',  'ব্লকড'],
                            ['01911223344', 'তানভীর আহমেদ', 'unknown', 'তথ্য নেই', 1,  'muted',   'নতুন'],
                        ];
                        foreach ($ds_rows as $row) :
                        ?>
                        <tr>
                            <td><input type="checkbox" class="gf-check" aria-label="<?php echo esc_attr($row[0]); ?>" /></td>
                            <td><strong class="gf-mono"><?php echo esc_html($row[0]); ?></strong></td>
                            <td><?php echo esc_html($row[1]); ?></td>
                            <td><span class="gf-risk-badge gf-risk-<?php echo esc_attr($row[2]); ?>"><?php echo esc_html($row[3]); ?></span></td>
                            <td class="gf-table-num"><?php echo esc_html($row[4]); ?></td>
                            <td><span class="gf-badge gf-badge-<?php echo esc_attr($row[5]); ?>"><?php echo esc_html($row[6]); ?></span></td>
                            <td class="gf-col-action">
                                <button type="button" class="gf-btn gf-btn-secondary gf-btn-sm">দেখুন</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="gf-card-footer">
            <nav class="gf-pagination" style="margin:0;" aria-label="পেজ নেভিগেশন">
                <span class="gf-page-disabled">&laquo;</span>
                <span class="gf-page-current" aria-current="page">১</span>
                <a href="#gf-ds-pagination">২</a>
                <a href="#gf-ds-pagination">৩</a>
                <span class="gf-page-dots">…</span>
                <a href="#gf-ds-pagination">৯</a>
                <a href="#gf-ds-pagination">&raquo;</a>
            </nav>
        </div>
    </div>

    <!-- ── Tabs ───────────────────────────────────────────────────────────── -->
    <div class="gf-card" data-gf-tabs>
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">ট্যাব</h2>
                <p class="gf-card-desc"><code>.gf-tabs .gf-tab[data-tab]</code> — প্যানেল <code>#gf-tab-&lt;name&gt;</code>। তীর চিহ্ন দিয়েও চলাচল করা যায়।</p>
            </div>
        </div>
        <div class="gf-tabs" role="tablist" style="margin:0;padding:0 1.375rem;">
            <button type="button" class="gf-tab active" data-tab="ds-one" role="tab" aria-selected="true">প্রথম</button>
            <button type="button" class="gf-tab" data-tab="ds-two" role="tab" aria-selected="false">
                দ্বিতীয় <span class="gf-count-pill gf-count-pill-muted">৭</span>
            </button>
            <button type="button" class="gf-tab" data-tab="ds-three" role="tab" aria-selected="false">তৃতীয়</button>
        </div>
        <div class="gf-card-body">
            <div class="gf-tab-content active" id="gf-tab-ds-one"><p>প্রথম ট্যাবের কনটেন্ট।</p></div>
            <div class="gf-tab-content" id="gf-tab-ds-two" hidden><p>দ্বিতীয় ট্যাবের কনটেন্ট।</p></div>
            <div class="gf-tab-content" id="gf-tab-ds-three" hidden><p>তৃতীয় ট্যাবের কনটেন্ট।</p></div>
        </div>
    </div>

    <!-- ── Stepper ────────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('স্টেপার', '.gf-stepper .gf-step .is-done .is-current'); ?>
        <p class="gf-help">অনবোর্ডিং উইজার্ডের জন্য। ৬০০px-এর নিচে উল্লম্ব হয়ে যায়।</p>
        <div class="gf-stepper">
            <div class="gf-step is-done">
                <span class="gf-step-marker" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                </span>
                <span class="gf-step-body">
                    <span class="gf-step-label">অ্যাকাউন্ট সংযোগ</span>
                    <span class="gf-step-desc">সম্পন্ন</span>
                </span>
            </div>
            <div class="gf-step is-done">
                <span class="gf-step-marker" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                </span>
                <span class="gf-step-body">
                    <span class="gf-step-label">ফোন সিংক</span>
                    <span class="gf-step-desc">সম্পন্ন</span>
                </span>
            </div>
            <div class="gf-step is-current" aria-current="step">
                <span class="gf-step-marker" aria-hidden="true">৩</span>
                <span class="gf-step-body">
                    <span class="gf-step-label">সুরক্ষা নিয়ম</span>
                    <span class="gf-step-desc">চলছে</span>
                </span>
            </div>
            <div class="gf-step">
                <span class="gf-step-marker" aria-hidden="true">৪</span>
                <span class="gf-step-body">
                    <span class="gf-step-label">SMS টেমপ্লেট</span>
                    <span class="gf-step-desc">বাকি</span>
                </span>
            </div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Alerts, progress, skeleton ─────────────────────────────────────── -->
    <?php guardify_ds_section_open('অ্যালার্ট', '.gf-alert .gf-alert-success|warning|error|info'); ?>
        <div class="gf-alert gf-alert-success">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            <div><strong class="gf-alert-title">সংযোগ সফল</strong>আপনার API কী যাচাই হয়েছে এবং সুরক্ষা এখন সক্রিয়।</div>
        </div>
        <div class="gf-alert gf-alert-warning">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
            <div><strong class="gf-alert-title">SMS ব্যালেন্স কম</strong>৪২টি SMS বাকি। ব্যালেন্স শেষ হলে OTP ও নোটিফিকেশন বন্ধ হয়ে যাবে।</div>
        </div>
        <div class="gf-alert gf-alert-error">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
            <div><strong class="gf-alert-title">সাবস্ক্রিপশনের মেয়াদ শেষ</strong>ফ্রড স্কোর আর আপডেট হচ্ছে না।</div>
        </div>
        <div class="gf-alert gf-alert-info">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
            <div>প্রথম সিংক শেষ হওয়ার আগে অনেক গ্রাহকের স্কোর অসম্পূর্ণ দেখাবে।</div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <?php guardify_ds_section_open('প্রোগ্রেস ও স্কেলিটন', '.gf-progress .gf-progress-fill .gf-skeleton .gf-spinner'); ?>
        <div>
            <div class="gf-progress-label"><span>ফোন সিংক</span><strong>৬৮%</strong></div>
            <div class="gf-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="68">
                <span class="gf-progress-fill" style="width:68%;"></span>
            </div>
        </div>
        <div>
            <div class="gf-progress-label"><span>ডেলিভারি রেট</span><strong>৯২%</strong></div>
            <div class="gf-progress"><span class="gf-progress-fill gf-fill-success" style="width:92%;"></span></div>
        </div>
        <div>
            <div class="gf-progress-label"><span>রিটার্ন রেট</span><strong>২৪%</strong></div>
            <div class="gf-progress"><span class="gf-progress-fill gf-fill-danger" style="width:24%;"></span></div>
        </div>

        <div class="gf-section">
            <h3 class="gf-section-title">স্কেলিটন</h3>
            <div class="gf-section-body" style="max-width:420px;">
                <div class="gf-skeleton gf-skeleton-title"></div>
                <div class="gf-skeleton gf-skeleton-text"></div>
                <div class="gf-skeleton gf-skeleton-text"></div>
                <div class="gf-skeleton gf-skeleton-box gf-mt-2"></div>
            </div>
        </div>

        <div class="gf-row">
            <span class="gf-spinner" aria-hidden="true"></span>
            <span class="gf-help">স্পিনার — <code>.gf-spinner</code></span>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Empty state ────────────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('এম্পটি স্টেট', '.gf-empty-state'); ?>
        <div class="gf-empty-state">
            <div class="gf-empty-state-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
            </div>
            <p class="gf-empty-state-title">কোনো ফলাফল পাওয়া যায়নি</p>
            <p class="gf-empty-state-desc">এই ফোন নম্বরের কোনো কুরিয়ার রেকর্ড নেই। নতুন গ্রাহক হলে এটাই স্বাভাবিক।</p>
            <div class="gf-empty-state-actions">
                <button type="button" class="gf-btn gf-btn-primary gf-btn-sm">নতুন সার্চ</button>
                <button type="button" class="gf-btn gf-btn-ghost gf-btn-sm">সাহায্য</button>
            </div>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Overlays and toast ─────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('মোডাল, ড্রয়ার ও টোস্ট', '.gf-modal-overlay .gf-drawer-overlay .gf-toast'); ?>
        <p class="gf-help">
            মোডাল ও ড্রয়ার Escape চাপলে বা ব্যাকড্রপে ক্লিক করলে বন্ধ হয়, ফোকাস ভেতরে আটকে থাকে এবং বন্ধ হলে যে বাটন থেকে খোলা হয়েছিল সেখানে ফিরে যায়।
        </p>
        <div class="gf-row">
            <button type="button" class="gf-btn gf-btn-primary" data-gf-open="#gf-ds-modal">মোডাল খুলুন</button>
            <button type="button" class="gf-btn gf-btn-secondary" data-gf-open="#gf-ds-drawer">ড্রয়ার খুলুন</button>
            <button type="button" class="gf-btn gf-btn-secondary" id="gf-ds-toast-success">সাকসেস টোস্ট</button>
            <button type="button" class="gf-btn gf-btn-secondary" id="gf-ds-toast-error">এরর টোস্ট</button>
        </div>
    <?php guardify_ds_section_close(); ?>

    <!-- ── Tooltip & key-value ────────────────────────────────────────────── -->
    <?php guardify_ds_section_open('টুলটিপ ও কী-ভ্যালু লিস্ট', '.gf-tooltip[data-tooltip] .gf-kv'); ?>
        <div class="gf-row">
            <span class="gf-tooltip" data-tooltip="ডেলিভারি পারফরম্যান্স — মোট পার্সেলের কত শতাংশ সফলভাবে ডেলিভার হয়েছে" tabindex="0">
                DP রেশিও <span class="gf-tooltip-icon" aria-hidden="true">?</span>
            </span>
            <button type="button" class="gf-icon-btn gf-tooltip" data-tooltip="রিফ্রেশ করুন" aria-label="রিফ্রেশ">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
            </button>
        </div>

        <dl class="gf-kv">
            <dt>প্লাগইন ভার্সন</dt>
            <dd><code><?php echo esc_html(GUARDIFY_VERSION); ?></code></dd>
            <dt>ডোমেইন</dt>
            <dd class="gf-break"><?php echo esc_html(wp_parse_url(site_url(), PHP_URL_HOST)); ?></dd>
            <dt>সাবস্ক্রিপশন</dt>
            <dd><span class="gf-badge gf-badge-success">Business</span></dd>
        </dl>
    <?php guardify_ds_section_close(); ?>

    <div id="gf-ds-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-ds-modal-title" style="display:none;">
        <div class="gf-modal">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-ds-modal-title">ফোন নম্বর ব্লক করুন</h2>
                <button type="button" class="gf-modal-close" data-gf-close aria-label="বন্ধ করুন">&times;</button>
            </div>
            <div class="gf-modal-body">
                <div class="gf-field">
                    <label class="gf-label" for="gf-ds-modal-phone">ফোন নম্বর <span class="gf-required">*</span></label>
                    <input type="text" id="gf-ds-modal-phone" class="gf-input gf-input-mono" placeholder="01XXXXXXXXX" />
                </div>
                <div class="gf-field">
                    <label class="gf-label" for="gf-ds-modal-reason">কারণ</label>
                    <input type="text" id="gf-ds-modal-reason" class="gf-input" placeholder="ম্যানুয়াল ব্লক" />
                </div>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary" data-gf-close>বাতিল</button>
                <button type="button" class="gf-btn gf-btn-danger" data-gf-close>ব্লক করুন</button>
            </div>
        </div>
    </div>

    <div id="gf-ds-drawer" class="gf-drawer-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-ds-drawer-title" style="display:none;">
        <div class="gf-drawer">
            <div class="gf-drawer-header">
                <h2 class="gf-modal-title" id="gf-ds-drawer-title">গ্রাহকের বিস্তারিত</h2>
                <button type="button" class="gf-modal-close" data-gf-close aria-label="বন্ধ করুন">&times;</button>
            </div>
            <div class="gf-drawer-body gf-stack">
                <div class="gf-risk gf-risk-medium">
                    <div class="gf-risk-dial" style="--gf-risk-pct: 62;">
                        <span class="gf-risk-score">৬২<small>%</small></span>
                    </div>
                    <div class="gf-risk-meta">
                        <span class="gf-risk-band">মাঝারি ঝুঁকি</span>
                        <span class="gf-risk-label">ডেলিভারি সাকসেস রেট</span>
                        <span class="gf-risk-confidence">১৮টি পার্সেলের ভিত্তিতে</span>
                    </div>
                </div>
                <dl class="gf-kv">
                    <dt>ফোন</dt><dd class="gf-mono">01898765432</dd>
                    <dt>শহর</dt><dd>চট্টগ্রাম</dd>
                    <dt>মোট অর্ডার</dt><dd>৫</dd>
                    <dt>শেষ অর্ডার</dt><dd>৩ দিন আগে</dd>
                </dl>
            </div>
            <div class="gf-drawer-footer">
                <button type="button" class="gf-btn gf-btn-secondary" data-gf-close>বন্ধ</button>
                <button type="button" class="gf-btn gf-btn-primary" data-gf-close>কুরিয়ারে পাঠান</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function ($) {
    var GF = window.Guardify;

    $('#gf-ds-toast-success').on('click', function () {
        GF.toast('১২টি ফোন নম্বর আনব্লক হয়েছে।', { type: 'success', title: 'সম্পন্ন' });
    });

    $('#gf-ds-toast-error').on('click', function () {
        GF.toast('সার্ভারে সংযোগ করা যায়নি। আবার চেষ্টা করুন।', { type: 'error' });
    });
});
</script>
