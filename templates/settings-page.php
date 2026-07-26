<?php
/**
 * Guardify Pro — Settings page.
 *
 * Around forty options live here. They are grouped by the decision a merchant
 * is making rather than by the module that implements them: everything that
 * can refuse an order sits together under সুরক্ষা, everything about SMS sits
 * under নোটিফিকেশন. Each control carries a one-line Bengali explanation of
 * what it does and when to want it — an option a merchant cannot understand is
 * an option they will never turn on, which is the same as not shipping it.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$api       = new Guardify_API();
$connected = $api->is_connected();
$api_key   = get_option('guardify_api_key', '');

// Fetch active banner and announcements (cached 15 min)
$banner_data = null;
$announcements_data = [];
if ($connected) {
    $banner_data = get_transient('gf_settings_banner');
    if (false === $banner_data) {
        $banner_result = $api->get('/api/v1/content/banner');
        if (!empty($banner_result['active'])) {
            $banner_data = $banner_result;
        } elseif (!empty($banner_result['data']['active'])) {
            $banner_data = $banner_result['data'];
        } else {
            $banner_data = null;
        }
        set_transient('gf_settings_banner', $banner_data ?: 'empty', 15 * MINUTE_IN_SECONDS);
    }
    if ($banner_data === 'empty') {
        $banner_data = null;
    }

    $announcements_data = get_transient('gf_settings_announcements');
    if (false === $announcements_data) {
        $ann_result = $api->get('/api/v1/content/announcement');
        if (is_array($ann_result) && !isset($ann_result['error'])) {
            $announcements_data = isset($ann_result['data']) && is_array($ann_result['data']) ? $ann_result['data'] : $ann_result;
            if (!is_array($announcements_data)) {
                $announcements_data = [];
            }
        } else {
            $announcements_data = [];
        }
        set_transient('gf_settings_announcements', $announcements_data, 15 * MINUTE_IN_SECONDS);
    }
}

// Load all settings
$settings = [
    'smart_filter_enabled'        => get_option('guardify_smart_filter_enabled', 'yes'),
    'smart_filter_threshold'      => get_option('guardify_smart_filter_threshold', 70),
    'smart_filter_action'         => get_option('guardify_smart_filter_action', 'block'),
    'smart_filter_skip_new'       => get_option('guardify_smart_filter_skip_new', 'yes'),
    'otp_enabled'                 => get_option('guardify_otp_enabled', 'no'),
    'vpn_block_enabled'           => get_option('guardify_vpn_block_enabled', 'no'),
    'repeat_blocker_enabled'      => get_option('guardify_repeat_blocker_enabled', 'no'),
    'repeat_blocker_hours'        => get_option('guardify_repeat_blocker_hours', 24),
    'repeat_blocker_message'      => get_option('guardify_repeat_blocker_message', 'এই ফোন নম্বর থেকে ইতিমধ্যে অর্ডার করা হয়েছে। অনুগ্রহ করে %d ঘণ্টা পর আবার চেষ্টা করুন।'),
    'repeat_blocker_support'      => get_option('guardify_repeat_blocker_support', ''),
    'fraud_detection_enabled'     => get_option('guardify_fraud_detection_enabled', 'no'),
    'fraud_auto_block_dp'         => get_option('guardify_fraud_auto_block_dp', 0),
    'fraud_auto_block_count_enabled' => get_option('guardify_fraud_auto_block_count_enabled', 'no'),
    'fraud_auto_block_order_limit'   => get_option('guardify_fraud_auto_block_order_limit', 3),
    'fraud_auto_block_time_limit'    => get_option('guardify_fraud_auto_block_time_limit', 24),
    'fraud_blocked_user_title'    => get_option('guardify_blocked_user_title', 'অর্ডার ব্লক করা হয়েছে'),
    'fraud_blocked_user_message'  => get_option('guardify_blocked_user_message', 'নিরাপত্তার কারণে এই ডিভাইস/IP থেকে অর্ডার প্লেস করা ব্লক করা হয়েছে। সমস্যা থাকলে গ্রাহকসেবায় যোগাযোগ করুন।'),
    'fraud_support_number'        => get_option('guardify_fraud_support_number', ''),
    'sms_notifications_enabled'   => get_option('guardify_sms_notifications_enabled', 'no'),
    'notification_statuses'       => get_option('guardify_notification_statuses', []),
    'notification_templates'      => get_option('guardify_notification_templates', []),
    'incomplete_orders_enabled'   => get_option('guardify_incomplete_orders_enabled', 'no'),
    'incomplete_retention'        => get_option('guardify_incomplete_retention', 30),
    'incomplete_cooldown_enabled' => get_option('guardify_incomplete_cooldown_enabled', 'yes'),
    'incomplete_cooldown'         => get_option('guardify_incomplete_cooldown', 30),
    'phone_history_enabled'       => get_option('guardify_phone_history_enabled', 'yes'),
    'report_column_enabled'       => get_option('guardify_report_column_enabled', 'yes'),
];
if (!is_array($settings['notification_statuses'])) {
    $settings['notification_statuses'] = [];
}
if (!is_array($settings['notification_templates'])) {
    $settings['notification_templates'] = [];
}

// Default SMS templates
$default_templates = Guardify_Order_Notifications::default_templates();
$templates = array_merge($default_templates, $settings['notification_templates']);

// WC statuses for notification selection
$wc_statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : [];

/**
 * A labelled switch row. Every feature toggle answers the same two questions,
 * so it is one helper rather than nine hand-written blocks that drift apart.
 *
 * Guarded because a template is an include, and an include can be reached
 * twice in one request by anything that renders the page inside a buffer.
 *
 * @param string $name  Option name, posted verbatim.
 * @param string $label Short name of the feature.
 * @param string $desc  What it does, and when a merchant wants it.
 * @param string $value Current 'yes' / 'no'.
 */
if (!function_exists('guardify_render_toggle_row')) :
function guardify_render_toggle_row($name, $label, $desc, $value) {
    ?>
    <label class="gf-toggle-row">
        <span class="gf-toggle-info">
            <span class="gf-toggle-label"><?php echo esc_html($label); ?></span>
            <span class="gf-toggle-desc"><?php echo esc_html($desc); ?></span>
        </span>
        <span class="gf-switch">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="yes" <?php checked($value, 'yes'); ?> class="gf-setting-toggle" />
            <span class="gf-switch-slider"></span>
        </span>
    </label>
    <?php
}
endif;
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">G</div>
            <div>
                <h1 class="gf-page-title">Guardify Pro</h1>
                <p class="gf-page-desc">ফ্রড ডিটেকশন ও কুরিয়ার ইন্টেলিজেন্স — আপনার ই-কমার্সের নিরাপত্তা</p>
            </div>
        </div>
        <div class="gf-page-header-actions">
            <span class="gf-badge gf-badge-dot <?php echo $connected ? 'gf-badge-success' : 'gf-badge-danger'; ?>">
                <?php echo $connected ? esc_html__('সংযুক্ত', 'guardify-pro') : esc_html__('সংযুক্ত নয়', 'guardify-pro'); ?>
            </span>
            <span class="gf-badge gf-badge-muted">v<?php echo esc_html(GUARDIFY_VERSION); ?></span>
        </div>
    </div>

    <?php if (!$connected) : ?>

    <div class="gf-card gf-card-highlight" data-gf-tabs style="max-width: 620px;">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">প্লাগইন সংযুক্ত করুন</h2>
                <p class="gf-card-desc">সংযুক্ত না হলে ফ্রড স্কোর, কুরিয়ার ডেটা ও SMS — কিছুই কাজ করবে না।</p>
            </div>
        </div>

        <div class="gf-tabs" role="tablist" style="margin: 0; padding: 0 1.375rem;">
            <button type="button" class="gf-tab active" data-tab="method-auto" role="tab" aria-selected="true">অটো কানেক্ট</button>
            <button type="button" class="gf-tab" data-tab="method-manual" role="tab" aria-selected="false">ম্যানুয়াল কী</button>
        </div>

        <div class="gf-card-body">
            <div class="gf-tab-content active" id="gf-tab-method-auto">
                <p class="gf-help gf-mb-2">
                    আপনার <a href="https://guardify.pro" target="_blank" rel="noopener">guardify.pro</a> অ্যাকাউন্ট দিয়ে লগইন করুন — API কী স্বয়ংক্রিয়ভাবে তৈরি ও সেটআপ হয়ে যাবে। বেশিরভাগ ক্ষেত্রে এটাই সহজ পথ।
                </p>
                <form id="gf-auto-fetch-form" class="gf-stack">
                    <div class="gf-field">
                        <label class="gf-label" for="gf-login-email">ইমেইল</label>
                        <input type="email" id="gf-login-email" class="gf-input" placeholder="your@email.com" autocomplete="email" required />
                    </div>
                    <div class="gf-field">
                        <label class="gf-label" for="gf-login-password">পাসওয়ার্ড</label>
                        <input type="password" id="gf-login-password" class="gf-input" placeholder="••••••••" autocomplete="current-password" required />
                    </div>
                    <div class="gf-row">
                        <button type="submit" class="gf-btn gf-btn-primary" id="gf-auto-fetch-btn">লগইন ও কানেক্ট</button>
                        <span class="gf-help">অ্যাকাউন্ট নেই? <a href="https://guardify.pro/register" target="_blank" rel="noopener">রেজিস্টার করুন</a></span>
                    </div>
                </form>
            </div>

            <div class="gf-tab-content" id="gf-tab-method-manual" hidden>
                <p class="gf-help gf-mb-2">
                    <a href="https://guardify.pro/api-keys" target="_blank" rel="noopener">guardify.pro &rarr; API Keys</a> পেজ থেকে নতুন কী তৈরি করে কপি করুন, তারপর নিচে পেস্ট করুন।
                </p>
                <form id="gf-connect-form" class="gf-stack">
                    <div class="gf-field">
                        <label class="gf-label" for="gf-connection-key">API Key</label>
                        <input type="text" id="gf-connection-key" class="gf-input gf-input-mono" placeholder="gp_xxxx" autocomplete="off" spellcheck="false" required />
                        <span class="gf-help">কী সবসময় <code>gp_</code> দিয়ে শুরু হয়।</span>
                    </div>
                    <div>
                        <button type="submit" class="gf-btn gf-btn-primary" id="gf-connect-btn">সংযুক্ত করুন</button>
                    </div>
                </form>
            </div>

            <div id="gf-connect-msg" class="gf-mt-2" style="display:none;" role="alert"></div>
        </div>
    </div>

    <?php else : ?>

    <div class="gf-stats-grid">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">স্ট্যাটাস</p>
                <p class="gf-stat-value gf-stat-value-sm" id="gf-status-text">চেক হচ্ছে…</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">ডোমেইন</p>
                <p class="gf-stat-value gf-stat-value-sm gf-break" id="gf-domain-text"><?php echo esc_html(wp_parse_url(site_url(), PHP_URL_HOST)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-primary" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l2.9 6.3 6.9.8-5 4.7 1.3 6.8L12 17.4 5.9 20.6 7.2 13.8l-5-4.7 6.9-.8z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">সাবস্ক্রিপশন</p>
                <p class="gf-stat-value gf-stat-value-sm" id="gf-plan-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">মেয়াদ</p>
                <p class="gf-stat-value gf-stat-value-sm" id="gf-expiry-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">SMS ব্যালেন্স</p>
                <p class="gf-stat-value" id="gf-sms-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 3h13v13H1zM14 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2"/><circle cx="17.5" cy="18.5" r="2"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">Steadfast ব্যালেন্স</p>
                <p class="gf-stat-value" id="gf-steadfast-balance">—</p>
            </div>
        </div>
    </div>

    <?php if ($banner_data && !empty($banner_data['message'])) : ?>
    <div class="gf-alert gf-alert-warning gf-mb-2">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11l18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 11-5.8-1.6"/></svg>
        <div>
            <?php echo esc_html($banner_data['message']); ?>
            <?php if (!empty($banner_data['url'])) : ?>
                <a href="<?php echo esc_url($banner_data['url']); ?>" target="_blank" rel="noopener">বিস্তারিত দেখুন →</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($announcements_data)) : ?>
    <div class="gf-card gf-mb-3">
        <div class="gf-card-header">
            <h2 class="gf-card-title">ঘোষণা</h2>
        </div>
        <div class="gf-card-body gf-flush">
            <table class="gf-table">
                <tbody>
                <?php foreach ($announcements_data as $ann) : ?>
                    <tr>
                        <td><?php echo esc_html(isset($ann['message']) ? $ann['message'] : ''); ?></td>
                        <td class="gf-col-action"><span class="gf-badge gf-badge-muted">v<?php echo esc_html(isset($ann['version']) ? $ann['version'] : ''); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div data-gf-tabs>

        <div class="gf-tabs" role="tablist">
            <button type="button" class="gf-tab active" data-tab="features" role="tab" aria-selected="true">ফিচার</button>
            <button type="button" class="gf-tab" data-tab="protection" role="tab" aria-selected="false">সুরক্ষা নিয়ম</button>
            <button type="button" class="gf-tab" data-tab="notifications" role="tab" aria-selected="false">SMS নোটিফিকেশন</button>
            <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-sms-logs')); ?>" class="gf-tab">SMS লগস ↗</a>
            <button type="button" class="gf-tab" data-tab="connection" role="tab" aria-selected="false">সংযোগ</button>
            <button type="button" class="gf-tab" data-tab="support" role="tab" aria-selected="false">সাপোর্ট</button>
            <button type="button" class="gf-tab" data-tab="update" role="tab" aria-selected="false">আপডেট</button>
        </div>

        <!-- ── Tab: Features ────────────────────────────────────────────── -->
        <div class="gf-tab-content active" id="gf-tab-features">
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">কোন ফিচার চালু থাকবে</h2>
                        <p class="gf-card-desc">প্রতিটি ফিচার আলাদাভাবে চালু বা বন্ধ করা যায়। নিচে সেভ বাটনে ক্লিক করার পর পরিবর্তন কার্যকর হবে।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-settings-list">
                        <?php
                        $features = [
                            [
                                'key'   => 'guardify_smart_filter_enabled',
                                'label' => 'স্মার্ট অর্ডার ফিল্টার',
                                'desc'  => 'গ্রাহকের কুরিয়ার ডেলিভারি রেকর্ড (DP রেশিও) দেখে ঝুঁকিপূর্ণ অর্ডার ব্লক, OTP বা ফ্ল্যাগ করে। COD অর্ডার বেশি রিটার্ন হলে প্রথমে এটাই চালু করুন।',
                                'val'   => $settings['smart_filter_enabled'],
                            ],
                            [
                                'key'   => 'guardify_otp_enabled',
                                'label' => 'OTP ভেরিফিকেশন',
                                'desc'  => 'চেকআউটে গ্রাহকের ফোনে SMS কোড পাঠিয়ে নম্বর যাচাই করে। ভুয়া নম্বরে অর্ডার আটকায়, তবে প্রতি অর্ডারে SMS খরচ হয়।',
                                'val'   => $settings['otp_enabled'],
                            ],
                            [
                                'key'   => 'guardify_vpn_block_enabled',
                                'label' => 'VPN / প্রক্সি ব্লক',
                                'desc'  => 'VPN বা প্রক্সির পেছন থেকে আসা চেকআউট আটকায়। কিছু বৈধ গ্রাহকও VPN ব্যবহার করেন — বারবার একই জায়গা থেকে ভুয়া অর্ডার এলে চালু করুন।',
                                'val'   => $settings['vpn_block_enabled'],
                            ],
                            [
                                'key'   => 'guardify_repeat_blocker_enabled',
                                'label' => 'রিপিট অর্ডার ব্লকার',
                                'desc'  => 'একই ফোন নম্বর থেকে অল্প সময়ে একাধিক অর্ডার আটকায়। ভুল করে দুইবার অর্ডার হওয়া বা এক নম্বর দিয়ে স্প্যাম বন্ধ করে।',
                                'val'   => $settings['repeat_blocker_enabled'],
                            ],
                            [
                                'key'   => 'guardify_fraud_detection_enabled',
                                'label' => 'ফ্রড ডিটেকশন',
                                'desc'  => 'ডিভাইস ফিঙ্গারপ্রিন্ট ও IP ট্র্যাক করে চেনা প্রতারককে চিনে রাখে এবং নিয়ম অনুযায়ী অটো-ব্লক করে।',
                                'val'   => $settings['fraud_detection_enabled'],
                            ],
                            [
                                'key'   => 'guardify_sms_notifications_enabled',
                                'label' => 'SMS নোটিফিকেশন',
                                'desc'  => 'অর্ডারের স্ট্যাটাস বদলালে গ্রাহককে SMS পাঠায়। কোন স্ট্যাটাসে ও কী লেখা যাবে তা SMS নোটিফিকেশন ট্যাবে ঠিক করুন।',
                                'val'   => $settings['sms_notifications_enabled'],
                            ],
                            [
                                'key'   => 'guardify_incomplete_orders_enabled',
                                'label' => 'ইনকমপ্লিট অর্ডার',
                                'desc'  => 'চেকআউট পেজে নাম-ফোন দিয়েও অর্ডার শেষ না করা গ্রাহকদের ধরে রাখে, যাতে আপনি ফোন বা SMS দিয়ে অর্ডারটি ফিরিয়ে আনতে পারেন।',
                                'val'   => $settings['incomplete_orders_enabled'],
                            ],
                            [
                                'key'   => 'guardify_phone_history_enabled',
                                'label' => 'ফোন হিস্ট্রি',
                                'desc'  => 'WooCommerce অর্ডার লিস্টে দেখায় এই নম্বর থেকে আগে কতটি অর্ডার এসেছে। পুরনো গ্রাহক চেনার সবচেয়ে দ্রুত উপায়।',
                                'val'   => $settings['phone_history_enabled'],
                            ],
                            [
                                'key'   => 'guardify_report_column_enabled',
                                'label' => 'রিপোর্ট কলাম',
                                'desc'  => 'অর্ডার লিস্টে DP রেশিও ও রিস্ক ব্যাজ যোগ করে, যাতে অর্ডার খোলার আগেই ঝুঁকি দেখা যায়।',
                                'val'   => $settings['report_column_enabled'],
                            ],
                        ];
                        foreach ($features as $f) {
                            guardify_render_toggle_row($f['key'], $f['label'], $f['desc'], $f['val']);
                        }
                        ?>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ইনকমপ্লিট অর্ডার সেটিংস</h2>
                        <p class="gf-card-desc">অসম্পূর্ণ চেকআউটের রেকর্ড কতদিন রাখা হবে এবং কখন আবার ক্যাপচার করা হবে।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field gf-field-narrow">
                        <label class="gf-label" for="gf-incomplete-retention">রেকর্ড রাখার সময় (দিন)</label>
                        <input type="number" id="gf-incomplete-retention" name="guardify_incomplete_retention" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['incomplete_retention']); ?>" min="0" max="365" step="1" />
                        <span class="gf-help">এই দিন পার হলে পুরনো পেন্ডিং রেকর্ড অটো-ডিলিট হবে। ডাটাবেইজ হালকা রাখতে ৩০ দিনই যথেষ্ট।</span>
                    </div>

                    <div class="gf-settings-list">
                        <?php
                        guardify_render_toggle_row(
                            'guardify_incomplete_cooldown_enabled',
                            'কুলডাউন সক্রিয়',
                            'একজন গ্রাহক অর্ডার সম্পন্ন করার পর নির্দিষ্ট সময় পর্যন্ত তার নতুন অসম্পূর্ণ চেকআউট আর ক্যাপচার হবে না। এতে একই গ্রাহকের একই কার্ট বারবার লিস্টে আসে না।',
                            $settings['incomplete_cooldown_enabled']
                        );
                        ?>
                    </div>

                    <div class="gf-field gf-field-narrow" data-gf-depends-on="guardify_incomplete_cooldown_enabled">
                        <label class="gf-label" for="gf-incomplete-cooldown">কুলডাউন সময় (মিনিট)</label>
                        <input type="number" id="gf-incomplete-cooldown" name="guardify_incomplete_cooldown" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['incomplete_cooldown']); ?>" min="5" max="43200" step="1" />
                        <span class="gf-help">ডিফল্ট ৩০ মিনিট। গ্রাহক যদি সাধারণত একই দিনে ফিরে আসেন, ছোট মানই ভালো।</span>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">কুরিয়ার</h2>
                        <p class="gf-card-desc">অর্ডার পাঠানোর সময় কোন কুরিয়ার আগে থেকেই সিলেক্ট থাকবে।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-field gf-field-mid">
                        <label class="gf-label" for="gf-default-courier">ডিফল্ট কুরিয়ার</label>
                        <select id="gf-default-courier" name="guardify_default_courier" class="gf-select gf-setting-input">
                            <option value="steadfast" <?php selected(get_option('guardify_default_courier', 'steadfast'), 'steadfast'); ?>>Steadfast</option>
                            <option value="pathao" <?php selected(get_option('guardify_default_courier', 'steadfast'), 'pathao'); ?>>Pathao</option>
                        </select>
                        <span class="gf-help">প্রতিটি অর্ডারে আলাদা কুরিয়ার বেছে নেওয়া যাবে — এটি শুধু ডিফল্ট।</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Tab: Protection rules ────────────────────────────────────── -->
        <div class="gf-tab-content" id="gf-tab-protection" hidden>
            <div class="gf-alert gf-alert-info gf-mb-3">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                <div>
                    <strong class="gf-alert-title">নিয়ম কড়া করার আগে</strong>
                    এই ট্যাবের নিয়মগুলো গ্রাহকের অর্ডার আটকাতে পারে। প্রথমে অ্যাকশন <strong>ফ্ল্যাগ</strong> রেখে কয়েক দিন দেখুন কারা ধরা পড়ছে — তারপর ব্লকে যান। খুব কড়া নিয়ম ভালো গ্রাহকও হারায়।
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">স্মার্ট ফিল্টার</h2>
                        <p class="gf-card-desc">DP রেশিও = গ্রাহকের আগের পার্সেলের মধ্যে কতগুলো সফলভাবে ডেলিভার হয়েছে। যত কম, তত ঝুঁকি।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field-row">
                        <div class="gf-field">
                            <label class="gf-label" for="gf-sf-threshold">DP থ্রেশহোল্ড (%)</label>
                            <input type="number" id="gf-sf-threshold" name="guardify_smart_filter_threshold" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['smart_filter_threshold']); ?>" min="0" max="100" step="1" />
                            <span class="gf-help">এর নিচে DP হলে অ্যাকশন নেওয়া হবে। ৭০% দিয়ে শুরু করুন; ১০ জনে ৩ জনের বেশি পার্সেল ফেরত দিলে সে ধরা পড়বে।</span>
                        </div>
                        <div class="gf-field">
                            <label class="gf-label" for="gf-sf-action">অ্যাকশন</label>
                            <select id="gf-sf-action" name="guardify_smart_filter_action" class="gf-select gf-setting-input">
                                <option value="block" <?php selected($settings['smart_filter_action'], 'block'); ?>>ব্লক করুন — অর্ডার হবে না</option>
                                <option value="otp" <?php selected($settings['smart_filter_action'], 'otp'); ?>>OTP ভেরিফিকেশন — নম্বর যাচাই করে ছাড়</option>
                                <option value="flag" <?php selected($settings['smart_filter_action'], 'flag'); ?>>ফ্ল্যাগ করুন — অর্ডার হবে, আপনি চিহ্ন দেখবেন</option>
                            </select>
                            <span class="gf-help">নিশ্চিত না হলে ফ্ল্যাগ বেছে নিন — কোনো অর্ডার হারাবে না।</span>
                        </div>
                    </div>

                    <div class="gf-settings-list">
                        <?php
                        guardify_render_toggle_row(
                            'guardify_smart_filter_skip_new',
                            'নতুন গ্রাহক বাদ দিন',
                            'যার কোনো কুরিয়ার হিস্ট্রি নেই তার উপর ফিল্টার চলবে না। বন্ধ রাখলে প্রথমবার অর্ডার করা গ্রাহকও আটকে যেতে পারে — সাধারণত চালু রাখাই ভালো।',
                            $settings['smart_filter_skip_new']
                        );
                        ?>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">রিপিট অর্ডার ব্লকার</h2>
                        <p class="gf-card-desc">একই ফোন নম্বর থেকে অল্প সময়ে আবার অর্ডার এলে কী হবে।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field gf-field-narrow">
                        <label class="gf-label" for="gf-rb-hours">অপেক্ষার সময় (ঘণ্টা)</label>
                        <input type="number" id="gf-rb-hours" name="guardify_repeat_blocker_hours" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_hours']); ?>" min="1" max="720" />
                        <span class="gf-help">এই সময়ের মধ্যে একই নম্বরে দ্বিতীয় অর্ডার নেওয়া হবে না। ২৪ ঘণ্টা বেশিরভাগ দোকানের জন্য ঠিক আছে।</span>
                    </div>
                    <div class="gf-field gf-field-wide">
                        <label class="gf-label" for="gf-rb-message">গ্রাহক যে মেসেজ দেখবে</label>
                        <input type="text" id="gf-rb-message" name="guardify_repeat_blocker_message" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_message']); ?>" />
                        <span class="gf-help">লেখার মধ্যে <code>%d</code> বসালে সেখানে ঘণ্টার সংখ্যা আপনা-আপনি বসে যাবে।</span>
                    </div>
                    <div class="gf-field gf-field-narrow">
                        <label class="gf-label" for="gf-rb-support">সাপোর্ট ফোন নম্বর <span class="gf-text-muted">(ঐচ্ছিক)</span></label>
                        <input type="text" id="gf-rb-support" name="guardify_repeat_blocker_support" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_support']); ?>" placeholder="01XXXXXXXXX" inputmode="tel" />
                        <span class="gf-help">দিলে পপআপে “কল করুন” বাটন আসবে — সত্যিকারের গ্রাহক আটকে গেলে সে সরাসরি ফোন করতে পারবে।</span>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ফ্রড অটো-ব্লক</h2>
                        <p class="gf-card-desc">কোন শর্তে একটি ফোন নম্বর নিজে থেকেই ব্লক লিস্টে চলে যাবে। ব্লক লিস্ট ফ্রড ম্যানেজমেন্ট পেজ থেকে দেখা ও আনব্লক করা যায়।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field gf-field-narrow">
                        <label class="gf-label" for="gf-fraud-dp">DP থ্রেশহোল্ড (%)</label>
                        <input type="number" id="gf-fraud-dp" name="guardify_fraud_auto_block_dp" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_dp']); ?>" min="0" max="100" step="1" />
                        <span class="gf-help"><strong>০ দিলে এই নিয়ম বন্ধ।</strong> এর নিচে DP হলে নম্বর স্থায়ীভাবে ব্লক হবে — স্মার্ট ফিল্টারের চেয়ে কঠিন ব্যবস্থা, তাই কম মান (যেমন ৩০) দিন।</span>
                    </div>

                    <div class="gf-section">
                        <div class="gf-settings-list">
                            <?php
                            guardify_render_toggle_row(
                                'guardify_fraud_auto_block_count_enabled',
                                'অর্ডার সংখ্যা অনুযায়ী অটো-ব্লক',
                                'অল্প সময়ে একই নম্বর থেকে অনেক অর্ডার এলে সেটি বট বা স্প্যাম হওয়ার সম্ভাবনা বেশি — তখন নম্বরটি ব্লক হবে।',
                                $settings['fraud_auto_block_count_enabled']
                            );
                            ?>
                        </div>
                        <div class="gf-field-row gf-mt-2" data-gf-depends-on="guardify_fraud_auto_block_count_enabled">
                            <div class="gf-field">
                                <label class="gf-label" for="gf-fraud-order-limit">অর্ডার সীমা</label>
                                <input type="number" id="gf-fraud-order-limit" name="guardify_fraud_auto_block_order_limit" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_order_limit']); ?>" min="1" max="50" />
                                <span class="gf-help">কতটি অর্ডারের পর ব্লক হবে।</span>
                            </div>
                            <div class="gf-field">
                                <label class="gf-label" for="gf-fraud-time-limit">সময়সীমা (ঘণ্টা)</label>
                                <input type="number" id="gf-fraud-time-limit" name="guardify_fraud_auto_block_time_limit" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_time_limit']); ?>" min="1" max="720" />
                                <span class="gf-help">কত ঘণ্টার মধ্যে অর্ডার গোনা হবে। “২৪ ঘণ্টায় ৩টি” বেশিরভাগ দোকানে নিরাপদ।</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ব্লক হওয়া গ্রাহক যা দেখবে</h2>
                        <p class="gf-card-desc">ব্লক করা কেউ চেকআউট করতে গেলে এই পপআপ দেখবে। ভদ্র ভাষা রাখুন — ভুল করে ব্লক হওয়া সত্যিকারের গ্রাহকও এটি পড়বেন।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field gf-field-mid">
                        <label class="gf-label" for="gf-blocked-title">পপআপ টাইটেল</label>
                        <input type="text" id="gf-blocked-title" name="guardify_blocked_user_title" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_blocked_user_title']); ?>" />
                    </div>
                    <div class="gf-field gf-field-wide">
                        <label class="gf-label" for="gf-blocked-message">পপআপ মেসেজ</label>
                        <textarea id="gf-blocked-message" name="guardify_blocked_user_message" class="gf-input gf-setting-input" rows="3"><?php echo esc_textarea($settings['fraud_blocked_user_message']); ?></textarea>
                    </div>
                    <div class="gf-field gf-field-narrow">
                        <label class="gf-label" for="gf-fraud-support">সাপোর্ট ফোন নম্বর <span class="gf-text-muted">(ঐচ্ছিক)</span></label>
                        <input type="text" id="gf-fraud-support" name="guardify_fraud_support_number" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_support_number']); ?>" placeholder="01XXXXXXXXX" inputmode="tel" />
                        <span class="gf-help">দিলে পপআপে কল বাটন দেখাবে।</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Tab: SMS notifications ───────────────────────────────────── -->
        <div class="gf-tab-content" id="gf-tab-notifications" hidden>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">কোন স্ট্যাটাসে SMS যাবে</h2>
                        <p class="gf-card-desc">প্রতিটি SMS আপনার ব্যালেন্স থেকে কাটে। যেগুলো গ্রাহকের সত্যিই জানা দরকার — যেমন অর্ডার নিশ্চিত হওয়া ও কুরিয়ারে দেওয়া — সেগুলোই বাছুন।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <?php if (empty($wc_statuses)) : ?>
                        <p class="gf-help">WooCommerce স্ট্যাটাস পাওয়া যায়নি।</p>
                    <?php else : ?>
                    <div class="gf-checkbox-grid">
                        <?php foreach ($wc_statuses as $slug => $label) : ?>
                        <label class="gf-checkbox-item">
                            <input type="checkbox" class="gf-check gf-setting-toggle" name="guardify_notification_statuses[]" value="<?php echo esc_attr($slug); ?>"
                                <?php checked(in_array($slug, $settings['notification_statuses'], true)); ?> />
                            <span><?php echo esc_html($label); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">SMS টেমপ্লেট</h2>
                        <p class="gf-card-desc">খালি রাখলে ওই স্ট্যাটাসে কোনো SMS যাবে না। বাংলা লেখা বেশি জায়গা নেয় — লম্বা মেসেজ একাধিক SMS হিসেবে গোনা হয়।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-alert gf-alert-info">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7V5h16v2M9 20h6M12 5v15"/></svg>
                        <div>
                            যে জায়গায় গ্রাহকের তথ্য বসাতে চান, সেখানে এগুলো লিখুন —
                            <code>{customer_name}</code>, <code>{order_number}</code>, <code>{product_name}</code>,
                            <code>{order_total}</code>, <code>{order_date}</code>, <code>{siteurl}</code>
                        </div>
                    </div>

                    <?php foreach ($wc_statuses as $slug => $label) : ?>
                    <div class="gf-field">
                        <label class="gf-label" for="gf-tpl-<?php echo esc_attr($slug); ?>"><?php echo esc_html($label); ?></label>
                        <textarea id="gf-tpl-<?php echo esc_attr($slug); ?>" name="guardify_notification_templates[<?php echo esc_attr($slug); ?>]" class="gf-input gf-setting-input" rows="2"><?php echo esc_textarea(isset($templates[$slug]) ? $templates[$slug] : ''); ?></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── Tab: Connection ──────────────────────────────────────────── -->
        <div class="gf-tab-content" id="gf-tab-connection" hidden>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ফোন ডেটা সিংক</h2>
                        <p class="gf-card-desc">আপনার পুরনো অর্ডারের ফোন নম্বরগুলো Guardify Engine-এ পাঠানো হয়, যাতে কুরিয়ার ডেটার সাথে মিলিয়ে DP রেশিও তৈরি করা যায়। প্রথম সিংক শেষ হওয়ার আগে অনেক গ্রাহকের স্কোর অসম্পূর্ণ দেখাবে।</p>
                    </div>
                    <div class="gf-card-header-actions">
                        <span id="gf-sync-badge" class="gf-badge gf-badge-muted">লোড হচ্ছে…</span>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-stats-grid gf-mb-1">
                        <div class="gf-stat-card">
                            <div class="gf-stat-body">
                                <p class="gf-stat-label">মোট অর্ডার</p>
                                <p class="gf-stat-value" id="gf-sync-total">—</p>
                            </div>
                        </div>
                        <div class="gf-stat-card">
                            <div class="gf-stat-body">
                                <p class="gf-stat-label">স্ক্যান হয়েছে</p>
                                <p class="gf-stat-value" id="gf-sync-scanned">—</p>
                            </div>
                        </div>
                        <div class="gf-stat-card">
                            <div class="gf-stat-body">
                                <p class="gf-stat-label">ফোন পাঠানো</p>
                                <p class="gf-stat-value" id="gf-sync-sent">—</p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div class="gf-progress-label">
                            <span>সিংক অগ্রগতি</span>
                            <strong id="gf-sync-pct">0%</strong>
                        </div>
                        <div class="gf-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="সিংক অগ্রগতি">
                            <span class="gf-progress-fill" id="gf-sync-progress"></span>
                        </div>
                    </div>

                    <div class="gf-row">
                        <button type="button" id="gf-manual-sync-btn" class="gf-btn gf-btn-secondary">এখনই সিংক করুন</button>
                        <span id="gf-sync-msg" class="gf-help"></span>
                    </div>
                </div>
            </div>

            <div class="gf-card gf-card-danger">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">সংযোগ বিচ্ছিন্ন করুন</h2>
                        <p class="gf-card-desc">API কী মুছে যাবে এবং এই সাইটে Guardify Pro-র সব সুরক্ষা বন্ধ হয়ে যাবে। আপনার সাবস্ক্রিপশন বা সংরক্ষিত ডেটা মুছবে না — আবার সংযুক্ত করলে সব ফিরে আসবে।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <button type="button" id="gf-disconnect-btn" class="gf-btn gf-btn-danger">সংযোগ বিচ্ছিন্ন করুন</button>
                </div>
            </div>
        </div>

        <!-- ── Tab: Support ─────────────────────────────────────────────── -->
        <div class="gf-tab-content" id="gf-tab-support" hidden>
            <div class="gf-card" style="max-width: 720px;">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">সাপোর্ট টিকেট পাঠান</h2>
                        <p class="gf-card-desc">সমস্যা বা প্রশ্ন থাকলে জানান। আপনার সাইটের ঠিকানা ও প্লাগইন ভার্সন টিকেটের সাথে অটোমেটিক যায়, আলাদা লিখতে হবে না।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <div class="gf-field gf-field-wide">
                        <label class="gf-label" for="gf-support-domain">আপনার ওয়েবসাইট</label>
                        <input type="text" id="gf-support-domain" class="gf-input" value="<?php echo esc_attr(site_url()); ?>" readonly />
                    </div>

                    <div class="gf-field gf-field-mid">
                        <label class="gf-label" for="gf-support-whatsapp">WhatsApp নম্বর <span class="gf-required">*</span></label>
                        <input type="text" id="gf-support-whatsapp" class="gf-input" placeholder="01XXXXXXXXX" inputmode="tel" />
                        <span class="gf-help">আমরা এই নম্বরেই যোগাযোগ করব।</span>
                    </div>

                    <div class="gf-field gf-field-mid">
                        <label class="gf-label" for="gf-support-type">বিষয় <span class="gf-required">*</span></label>
                        <select id="gf-support-type" class="gf-select">
                            <option value="সমস্যা রিপোর্ট">আমার একটি সমস্যা হচ্ছে</option>
                            <option value="ফিচার রিকোয়েস্ট">আমার একটি ফিচার সাজেশন আছে</option>
                            <option value="তথ্য প্রয়োজন">আমার তথ্য দরকার</option>
                            <option value="সাধারণ প্রশ্ন">আমার একটি প্রশ্ন আছে</option>
                            <option value="বিলিং">বিলিং সম্পর্কিত</option>
                        </select>
                    </div>

                    <div class="gf-field gf-field-wide gf-ticket-field gf-field-problem">
                        <label class="gf-label" for="gf-support-problem">সমস্যার বিবরণ <span class="gf-required">*</span></label>
                        <textarea id="gf-support-problem" class="gf-input" rows="5" placeholder="কী সমস্যা হচ্ছে? কখন শুরু হয়েছে? কোনো এরর মেসেজ আসছে?"></textarea>
                    </div>

                    <div class="gf-field gf-field-wide gf-ticket-field gf-field-feature" style="display:none;">
                        <label class="gf-label" for="gf-support-feature">ফিচারের বিবরণ <span class="gf-required">*</span></label>
                        <textarea id="gf-support-feature" class="gf-input" rows="5" placeholder="কোন ফিচার চাইছেন? এটা কীভাবে সাহায্য করবে?"></textarea>
                    </div>

                    <div class="gf-field gf-field-wide gf-ticket-field gf-field-info" style="display:none;">
                        <label class="gf-label" for="gf-support-info">কী তথ্য দরকার? <span class="gf-required">*</span></label>
                        <textarea id="gf-support-info" class="gf-input" rows="5" placeholder="আপনার কোন তথ্য প্রয়োজন?"></textarea>
                    </div>

                    <div class="gf-field gf-field-wide gf-ticket-field gf-field-general" style="display:none;">
                        <label class="gf-label" for="gf-support-general">আপনার প্রশ্ন <span class="gf-required">*</span></label>
                        <textarea id="gf-support-general" class="gf-input" rows="5" placeholder="আপনি কী জানতে চান?"></textarea>
                    </div>

                    <div class="gf-field gf-field-wide gf-ticket-field gf-field-billing" style="display:none;">
                        <label class="gf-label" for="gf-support-billing">বিলিং বিবরণ <span class="gf-required">*</span></label>
                        <textarea id="gf-support-billing" class="gf-input" rows="5" placeholder="বিলিং সম্পর্কে বিস্তারিত লিখুন…"></textarea>
                    </div>

                    <div>
                        <button type="button" id="gf-support-submit" class="gf-btn gf-btn-primary">মেসেজ পাঠান</button>
                        <p id="gf-support-msg" class="gf-error-text gf-mt-1" style="display:none;" role="alert"></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Tab: Update ──────────────────────────────────────────────── -->
        <div class="gf-tab-content" id="gf-tab-update" hidden>
            <div class="gf-card" style="max-width: 620px;">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">প্লাগইন আপডেট</h2>
                        <p class="gf-card-desc">নতুন রিলিজ হলে WordPress ড্যাশবোর্ডেই আপডেট নোটিফিকেশন আসবে। WordPress দিনে একবার চেক করে — এখনই দেখতে চাইলে নিচের বাটন ব্যবহার করুন।</p>
                    </div>
                </div>
                <div class="gf-card-body gf-stack">
                    <dl class="gf-kv">
                        <dt>বর্তমান ভার্সন</dt>
                        <dd><code><?php echo esc_html(GUARDIFY_VERSION); ?></code></dd>
                        <dt>রিলিজ পেজ</dt>
                        <dd><a href="https://github.com/GuardifyPro/Guardify-Plugin/releases" target="_blank" rel="noopener">GuardifyPro/Guardify-Plugin ↗</a></dd>
                    </dl>
                    <div>
                        <button type="button" id="gf-check-update-btn" class="gf-btn gf-btn-secondary">আপডেট চেক করুন</button>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- The save bar covers the option tabs only; the component layer hides it
         on tabs that have nothing to save. -->
    <div id="gf-save-wrap" class="gf-row gf-mt-3">
        <button type="button" id="gf-save-settings" class="gf-btn gf-btn-primary gf-btn-lg">সেটিংস সংরক্ষণ করুন</button>
        <span class="gf-help">সব ট্যাবের পরিবর্তন একসাথে সংরক্ষিত হবে।</span>
    </div>

    <div id="gf-ticket-popup" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-ticket-popup-title" style="display:none;">
        <div class="gf-modal gf-modal-sm">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-ticket-popup-title">টিকেট পাঠানো হয়েছে</h2>
                <button type="button" class="gf-modal-close" data-gf-close aria-label="বন্ধ করুন">&times;</button>
            </div>
            <div class="gf-modal-body">
                <p class="gf-help">আপনার মেসেজ আমরা পেয়েছি। আমাদের টিম শীঘ্রই WhatsApp-এ যোগাযোগ করবে।</p>
                <div id="gf-ticket-id-box" style="display:none;">
                    <label class="gf-label" for="gf-ticket-id-val">টিকেট ID</label>
                    <div class="gf-copy gf-mt-1">
                        <input type="text" id="gf-ticket-id-val" class="gf-copy-value" readonly />
                        <button type="button" class="gf-copy-btn" data-gf-copy="#gf-ticket-id-val">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 012-2h8"/></svg>
                            <span data-gf-copy-label>কপি</span>
                        </button>
                    </div>
                    <p class="gf-help gf-mt-1">যোগাযোগের সময় এই ID বললে আমরা দ্রুত খুঁজে পাব।</p>
                </div>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-primary" data-gf-close>ঠিক আছে</button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>
