<?php
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
?>

<div class="gf-wrap">
    <div class="gf-header">
        <div class="gf-header-left">
            <div class="gf-logo">G</div>
            <div>
                <h1 class="gf-page-title">Guardify Pro</h1>
                <p class="gf-page-desc">ফ্রড ডিটেকশন ও কুরিয়ার ইন্টেলিজেন্স</p>
            </div>
        </div>
        <span class="gf-badge <?php echo $connected ? 'gf-badge-success' : 'gf-badge-danger'; ?>">
            <?php echo $connected ? 'সংযুক্ত' : 'সংযুক্ত নয়'; ?>
        </span>
    </div>

    <?php if (!$connected) : ?>
    <!-- Connection card -->
    <div class="gf-card">
        <div class="gf-card-header">
            <h2 class="gf-card-title">প্লাগইন সংযুক্ত করুন</h2>
        </div>
        <div class="gf-card-body">
            <!-- Connection method tabs -->
            <div style="display: flex; gap: 0; margin-bottom: 1.25rem; border-bottom: 2px solid var(--gf-border, #e5e7eb); overflow-x: auto;">
                <button type="button" class="gf-connect-tab active" data-method="auto" style="padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; cursor: pointer; color: var(--gf-text-muted, #6b7280); transition: all 0.15s; white-space: nowrap; flex-shrink: 0;">
                    🔑 অটো কানেক্ট
                </button>
                <button type="button" class="gf-connect-tab" data-method="manual" style="padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; background: none; border: none; border-bottom: 2px solid transparent; margin-bottom: -2px; cursor: pointer; color: var(--gf-text-muted, #6b7280); transition: all 0.15s; white-space: nowrap; flex-shrink: 0;">
                    📋 ম্যানুয়াল কী
                </button>
            </div>

            <!-- Auto-fetch method (default) -->
            <div id="gf-method-auto">
                <p class="gf-text-muted" style="margin-bottom: 0.75rem;">
                    আপনার <a href="https://guardify.pro" target="_blank" rel="noopener">guardify.pro</a> অ্যাকাউন্ট দিয়ে লগইন করুন — API কী স্বয়ংক্রিয়ভাবে সেটআপ হবে।
                </p>
                <form id="gf-auto-fetch-form" class="gf-form">
                    <div class="gf-form-group" style="margin-bottom: 0.75rem;">
                        <label class="gf-label">ইমেইল</label>
                        <input type="email" id="gf-login-email" class="gf-input" placeholder="your@email.com" autocomplete="email" required />
                    </div>
                    <div class="gf-form-group" style="margin-bottom: 1rem;">
                        <label class="gf-label">পাসওয়ার্ড</label>
                        <input type="password" id="gf-login-password" class="gf-input" placeholder="••••••••" autocomplete="current-password" required />
                    </div>
                    <button type="submit" class="gf-btn gf-btn-primary" id="gf-auto-fetch-btn">
                        লগইন ও কানেক্ট
                    </button>
                    <p class="gf-text-muted" style="margin-top: 0.5rem; font-size: 0.75rem;">
                        অ্যাকাউন্ট নেই? <a href="https://guardify.pro/register" target="_blank" rel="noopener">রেজিস্টার করুন</a>
                    </p>
                </form>
            </div>

            <!-- Manual key method (hidden by default) -->
            <div id="gf-method-manual" style="display: none;">
                <p class="gf-text-muted" style="margin-bottom: 0.5rem;">
                    <a href="https://guardify.pro/api-keys" target="_blank" rel="noopener">guardify.pro &rarr; API Keys</a> পেজ থেকে নতুন কী তৈরি করে কপি করুন, তারপর নিচে পেস্ট করুন।
                </p>
                <form id="gf-connect-form" class="gf-form">
                    <div class="gf-form-group" style="margin-bottom: 1rem;">
                        <label class="gf-label">API Key</label>
                        <input type="text" id="gf-connection-key" class="gf-input" placeholder="gp_xxxx" autocomplete="off" required style="font-family: monospace;" />
                    </div>
                    <button type="submit" class="gf-btn gf-btn-primary" id="gf-connect-btn">
                        সংযুক্ত করুন
                    </button>
                </form>
            </div>

            <div id="gf-connect-msg" style="display:none; margin-top: 1rem;"></div>
        </div>
    </div>
    <?php else : ?>

    <!-- Status cards -->
    <div class="gf-stats-grid">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">স্ট্যাটাস</p>
                <p class="gf-stat-value" id="gf-status-text">চেক হচ্ছে...</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">ডোমেইন</p>
                <p class="gf-stat-value" id="gf-domain-text" style="font-size: 0.875rem; word-break: break-all;"><?php echo esc_html(wp_parse_url(site_url(), PHP_URL_HOST)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">সাবস্ক্রিপশন</p>
                <p class="gf-stat-value" id="gf-plan-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">মেয়াদ</p>
                <p class="gf-stat-value" id="gf-expiry-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">SMS ব্যালেন্স</p>
                <p class="gf-stat-value" id="gf-sms-text">—</p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 17a2 2 0 11-4 0 2 2 0 014 0zM19 17a2 2 0 11-4 0 2 2 0 014 0z"/><path d="M13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10a1 1 0 001 1h1m8-1a1 1 0 01-1 1H9m4-1V8a1 1 0 011-1h2.586a1 1 0 01.707.293l3.414 3.414a1 1 0 01.293.707V16a1 1 0 01-1 1h-1m-6-1a1 1 0 001 1h1M5 17a2 2 0 104 0m-4 0a2 2 0 114 0m6 0a2 2 0 104 0m-4 0a2 2 0 114 0"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">Steadfast ব্যালেন্স</p>
                <p class="gf-stat-value" id="gf-steadfast-balance">—</p>
            </div>
        </div>
    </div>

    <?php if ($banner_data && !empty($banner_data['message'])) : ?>
    <!-- Active Banner -->
    <div class="gf-card" style="margin-top: 1.25rem; border-left: 4px solid #f59e0b;">
        <div class="gf-card-body" style="padding: 1rem 1.25rem;">
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                <span style="font-size: 1.25rem;">📢</span>
                <div style="flex: 1;">
                    <p style="margin: 0; font-weight: 500; color: var(--gf-text);"><?php echo esc_html($banner_data['message']); ?></p>
                    <?php if (!empty($banner_data['url'])) : ?>
                    <a href="<?php echo esc_url($banner_data['url']); ?>" target="_blank" rel="noopener" style="font-size: 0.875rem; color: #3b82f6; text-decoration: underline;">বিস্তারিত দেখুন →</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($announcements_data)) : ?>
    <!-- Announcements -->
    <div class="gf-card" style="margin-top: 1rem;">
        <div class="gf-card-header">
            <h2 class="gf-card-title">📋 ঘোষণা</h2>
        </div>
        <div class="gf-card-body" style="padding: 0;">
            <?php foreach ($announcements_data as $ann) : ?>
            <div style="padding: 0.75rem 1.25rem; border-bottom: 1px solid var(--gf-border, #e5e7eb);">
                <p style="margin: 0; font-weight: 500; color: var(--gf-text);"><?php echo esc_html($ann['message'] ?? ''); ?></p>
                <span style="font-size: 0.75rem; color: var(--gf-muted, #6b7280);">v<?php echo esc_html($ann['version'] ?? ''); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="gf-tabs" style="margin-top: 2rem;">
        <button class="gf-tab active" data-tab="features">ফিচার সমূহ</button>
        <button class="gf-tab" data-tab="smart-filter">স্মার্ট ফিল্টার</button>
        <button class="gf-tab" data-tab="notifications">SMS নোটিফিকেশন</button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-sms-logs')); ?>" class="gf-tab" style="text-decoration: none;">SMS লগস ↗</a>
        <button class="gf-tab" data-tab="connection">সংযোগ</button>
        <button class="gf-tab" data-tab="support">সাপোর্ট</button>
        <button class="gf-tab" data-tab="update">আপডেট</button>
    </div>

    <!-- Tab: Features -->
    <div class="gf-tab-content" id="gf-tab-features">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ফিচার টগল</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1.5rem;">প্রতিটি ফিচার চালু বা বন্ধ করুন। পরিবর্তন সংরক্ষণের পর কার্যকর হবে।</p>

                <div class="gf-settings-list">
                    <?php
                    $features = [
                        ['key' => 'guardify_smart_filter_enabled', 'label' => 'স্মার্ট অর্ডার ফিল্টার', 'desc' => 'DP রেশিও অনুযায়ী অর্ডার ব্লক/OTP/ফ্ল্যাগ করে', 'val' => $settings['smart_filter_enabled']],
                        ['key' => 'guardify_otp_enabled', 'label' => 'OTP ভেরিফিকেশন', 'desc' => 'চেকআউটে SMS OTP ভেরিফিকেশন', 'val' => $settings['otp_enabled']],
                        ['key' => 'guardify_vpn_block_enabled', 'label' => 'VPN/প্রক্সি ব্লক', 'desc' => 'VPN বা প্রক্সি ব্যবহারকারীদের চেকআউট ব্লক', 'val' => $settings['vpn_block_enabled']],
                        ['key' => 'guardify_repeat_blocker_enabled', 'label' => 'রিপিট অর্ডার ব্লকার', 'desc' => 'নির্দিষ্ট সময়ে একই ফোনে একাধিক অর্ডার ব্লক', 'val' => $settings['repeat_blocker_enabled']],
                        ['key' => 'guardify_fraud_detection_enabled', 'label' => 'ফ্রড ডিটেকশন', 'desc' => 'ডিভাইস ফিঙ্গারপ্রিন্ট, IP ট্র্যাকিং ও অটো-ব্লক', 'val' => $settings['fraud_detection_enabled']],
                        ['key' => 'guardify_sms_notifications_enabled', 'label' => 'SMS নোটিফিকেশন', 'desc' => 'অর্ডার স্ট্যাটাস পরিবর্তনে SMS পাঠানো', 'val' => $settings['sms_notifications_enabled']],
                        ['key' => 'guardify_incomplete_orders_enabled', 'label' => 'ইনকমপ্লিট অর্ডার', 'desc' => 'অসম্পূর্ণ চেকআউট ক্যাপচার ও রিকভারি SMS', 'val' => $settings['incomplete_orders_enabled']],
                        ['key' => 'guardify_phone_history_enabled', 'label' => 'ফোন হিস্ট্রি', 'desc' => 'অর্ডার লিস্টে ফোন নম্বরে আগের অর্ডার সংখ্যা', 'val' => $settings['phone_history_enabled']],
                        ['key' => 'guardify_report_column_enabled', 'label' => 'রিপোর্ট কলাম', 'desc' => 'অর্ডার লিস্টে DP রেশিও ও রিস্ক ব্যাজ', 'val' => $settings['report_column_enabled']],
                    ];
                    foreach ($features as $f) :
                    ?>
                    <label class="gf-toggle-row">
                        <div class="gf-toggle-info">
                            <span class="gf-toggle-label"><?php echo esc_html($f['label']); ?></span>
                            <span class="gf-toggle-desc"><?php echo esc_html($f['desc']); ?></span>
                        </div>
                        <div class="gf-switch">
                            <input type="checkbox" name="<?php echo esc_attr($f['key']); ?>" value="yes" <?php checked($f['val'], 'yes'); ?> class="gf-setting-toggle" />
                            <span class="gf-switch-slider"></span>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:1.5rem; border-top:1px solid var(--gf-border, #e5e7eb); padding-top:1.5rem;">
                    <h3 style="font-size:0.9375rem; font-weight:600; margin-bottom:0.75rem; color:var(--gf-text);">� ইনকমপ্লিট অর্ডার সেটিংস</h3>
                    <div class="gf-form-row">
                        <div class="gf-form-group">
                            <label class="gf-label">রিটেনশন পিরিয়ড (দিন)</label>
                            <input type="number" name="guardify_incomplete_retention" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['incomplete_retention']); ?>" min="0" max="365" step="1" style="max-width:120px;" />
                            <span class="gf-text-muted" style="font-size:0.8125rem;">0 = কখনো মুছবে না। পুরাতন পেন্ডিং রেকর্ড এই দিন পর অটো-ডিলিট হবে।</span>
                        </div>
                    </div>
                    <label class="gf-toggle-row" style="margin-top:0.75rem;">
                        <div class="gf-toggle-info">
                            <span class="gf-toggle-label">কুলডাউন সক্রিয়</span>
                            <span class="gf-toggle-desc">অর্ডার সম্পন্ন হলে নির্দিষ্ট সময় পর্যন্ত একই ফোনে পুনরায় ক্যাপচার করবে না</span>
                        </div>
                        <div class="gf-switch">
                            <input type="checkbox" name="guardify_incomplete_cooldown_enabled" value="yes" <?php checked($settings['incomplete_cooldown_enabled'], 'yes'); ?> class="gf-setting-toggle" />
                            <span class="gf-switch-slider"></span>
                        </div>
                    </label>
                    <div class="gf-form-group" style="margin-top:0.75rem;">
                        <label class="gf-label">কুলডাউন সময় (মিনিট)</label>
                        <input type="number" name="guardify_incomplete_cooldown" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['incomplete_cooldown']); ?>" min="5" max="43200" step="1" style="max-width:150px;" />
                        <span class="gf-text-muted" style="font-size:0.8125rem;">৫ থেকে ৪৩২০০ মিনিট (৩০ দিন)। ডিফল্ট: ৩০ মিনিট।</span>
                    </div>
                </div>

                <div style="margin-top:1.5rem; border-top:1px solid var(--gf-border, #e5e7eb); padding-top:1.5rem;">
                    <h3 style="font-size:0.9375rem; font-weight:600; margin-bottom:0.75rem; color:var(--gf-text);">�🚚 কুরিয়ার সেটিংস</h3>
                    <div class="gf-form-group">
                        <label class="gf-label">ডিফল্ট কুরিয়ার</label>
                        <select name="guardify_default_courier" class="gf-input gf-setting-input" style="max-width:250px;">
                            <option value="steadfast" <?php selected(get_option('guardify_default_courier', 'steadfast'), 'steadfast'); ?>>Steadfast</option>
                            <option value="pathao" <?php selected(get_option('guardify_default_courier', 'steadfast'), 'pathao'); ?>>Pathao</option>
                        </select>
                        <span class="gf-text-muted" style="font-size:0.8125rem;">অর্ডার পাঠানোর সময় ডিফল্ট কোন কুরিয়ার সিলেক্ট থাকবে</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Smart Filter -->
    <div class="gf-tab-content" id="gf-tab-smart-filter" style="display: none;">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">স্মার্ট ফিল্টার সেটিংস</h2>
            </div>
            <div class="gf-card-body">
                <div class="gf-form-row">
                    <div class="gf-form-group">
                        <label class="gf-label">DP থ্রেশহোল্ড (%)</label>
                        <input type="number" name="guardify_smart_filter_threshold" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['smart_filter_threshold']); ?>" min="0" max="100" step="1" />
                        <span class="gf-text-muted" style="font-size: 0.8125rem;">এই % এর নিচে DP হলে অ্যাকশন নেওয়া হবে (ডিফল্ট: ৭০)</span>
                    </div>
                    <div class="gf-form-group">
                        <label class="gf-label">অ্যাকশন</label>
                        <select name="guardify_smart_filter_action" class="gf-input gf-setting-input">
                            <option value="block" <?php selected($settings['smart_filter_action'], 'block'); ?>>ব্লক করুন</option>
                            <option value="otp" <?php selected($settings['smart_filter_action'], 'otp'); ?>>OTP ভেরিফিকেশন</option>
                            <option value="flag" <?php selected($settings['smart_filter_action'], 'flag'); ?>>ফ্ল্যাগ করুন</option>
                        </select>
                    </div>
                </div>
                <label class="gf-toggle-row" style="margin-top: 1rem;">
                    <div class="gf-toggle-info">
                        <span class="gf-toggle-label">নতুন গ্রাহক বাদ দিন</span>
                        <span class="gf-toggle-desc">কুরিয়ার হিস্ট্রি না থাকলে ফিল্টার স্কিপ করবে</span>
                    </div>
                    <div class="gf-switch">
                        <input type="checkbox" name="guardify_smart_filter_skip_new" value="yes" <?php checked($settings['smart_filter_skip_new'], 'yes'); ?> class="gf-setting-toggle" />
                        <span class="gf-switch-slider"></span>
                    </div>
                </label>
            </div>
        </div>

        <div class="gf-card" style="margin-top: 1.5rem;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">রিপিট ব্লকার সেটিংস</h2>
            </div>
            <div class="gf-card-body">
                <div class="gf-form-group" style="max-width: 300px;">
                    <label class="gf-label">ব্লক সময়সীমা (ঘন্টা)</label>
                    <input type="number" name="guardify_repeat_blocker_hours" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_hours']); ?>" min="1" max="720" />
                    <span class="gf-text-muted" style="font-size: 0.8125rem;">একই ফোনে পুনরায় অর্ডারের জন্য ন্যূনতম অপেক্ষার সময়</span>
                </div>
                <div class="gf-form-group" style="max-width: 500px; margin-top: 1rem;">
                    <label class="gf-label">কাস্টম এরর মেসেজ</label>
                    <input type="text" name="guardify_repeat_blocker_message" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_message']); ?>" />
                    <span class="gf-text-muted" style="font-size: 0.8125rem;">%d লিখলে সেখানে ঘন্টার সংখ্যা বসবে</span>
                </div>
                <div class="gf-form-group" style="max-width: 300px; margin-top: 1rem;">
                    <label class="gf-label">সাপোর্ট ফোন নম্বর (ঐচ্ছিক)</label>
                    <input type="text" name="guardify_repeat_blocker_support" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['repeat_blocker_support']); ?>" placeholder="01XXXXXXXXX" />
                    <span class="gf-text-muted" style="font-size: 0.8125rem;">পপআপে "কল করুন" বাটন দেখাবে</span>
                </div>
            </div>
        </div>

        <div class="gf-card" style="margin-top: 1.5rem;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ফ্রড অটো-ব্লক</h2>
            </div>
            <div class="gf-card-body">
                <div class="gf-form-group" style="max-width: 300px;">
                    <label class="gf-label">অটো-ব্লক DP থ্রেশহোল্ড (%)</label>
                    <input type="number" name="guardify_fraud_auto_block_dp" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_dp']); ?>" min="0" max="100" step="1" />
                    <span class="gf-text-muted" style="font-size: 0.8125rem;">০ = অটো-ব্লক অফ। এই % এর নিচে DP হলে ফোন নম্বর অটো-ব্লক হবে।</span>
                </div>

                <div style="margin-top: 1.25rem; padding-top: 1.25rem; border-top: 1px solid var(--gf-border);">
                    <label class="gf-setting-row">
                        <div>
                            <strong>অর্ডার সংখ্যা অনুযায়ী অটো-ব্লক</strong>
                            <p class="gf-text-muted" style="font-size: 0.8125rem; margin: 2px 0 0;">নির্দিষ্ট সময়ে নির্দিষ্ট সংখ্যার বেশি অর্ডার আসলে অটো-ব্লক</p>
                        </div>
                        <div class="gf-switch">
                            <input type="checkbox" name="guardify_fraud_auto_block_count_enabled" value="yes" <?php checked($settings['fraud_auto_block_count_enabled'], 'yes'); ?> class="gf-setting-toggle" />
                            <span class="gf-switch-slider"></span>
                        </div>
                    </label>
                    <div class="gf-form-group" style="max-width: 300px; margin-top: 0.75rem;">
                        <label class="gf-label">অটো-ব্লক অর্ডার সীমা</label>
                        <input type="number" name="guardify_fraud_auto_block_order_limit" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_order_limit']); ?>" min="1" max="50" />
                        <span class="gf-text-muted" style="font-size: 0.8125rem;">সর্বোচ্চ কতটি অর্ডার পর ব্লক হবে</span>
                    </div>
                    <div class="gf-form-group" style="max-width: 300px; margin-top: 0.75rem;">
                        <label class="gf-label">অটো-ব্লক সময়সীমা (ঘন্টা)</label>
                        <input type="number" name="guardify_fraud_auto_block_time_limit" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_auto_block_time_limit']); ?>" min="1" max="720" />
                        <span class="gf-text-muted" style="font-size: 0.8125rem;">কত ঘন্টার মধ্যে অর্ডার গুনবে</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="gf-card" style="margin-top: 1.5rem;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ব্লক করা ব্যবহারকারীর পপআপ</h2>
            </div>
            <div class="gf-card-body">
                <div class="gf-form-group" style="max-width: 400px;">
                    <label class="gf-label">পপআপ টাইটেল</label>
                    <input type="text" name="guardify_blocked_user_title" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_blocked_user_title']); ?>" />
                </div>
                <div class="gf-form-group" style="max-width: 500px; margin-top: 1rem;">
                    <label class="gf-label">পপআপ মেসেজ</label>
                    <textarea name="guardify_blocked_user_message" class="gf-input gf-setting-input" rows="3" style="resize: vertical;"><?php echo esc_textarea($settings['fraud_blocked_user_message']); ?></textarea>
                </div>
                <div class="gf-form-group" style="max-width: 300px; margin-top: 1rem;">
                    <label class="gf-label">সাপোর্ট ফোন নম্বর (ঐচ্ছিক)</label>
                    <input type="text" name="guardify_fraud_support_number" class="gf-input gf-setting-input" value="<?php echo esc_attr($settings['fraud_support_number']); ?>" placeholder="01XXXXXXXXX" />
                    <span class="gf-text-muted" style="font-size: 0.8125rem;">ব্লক পপআপে কল বাটন দেখাবে</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Notifications -->
    <div class="gf-tab-content" id="gf-tab-notifications" style="display: none;">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">SMS নোটিফিকেশন স্ট্যাটাস</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">কোন স্ট্যাটাসে SMS পাঠাতে চান সিলেক্ট করুন:</p>
                <div class="gf-checkbox-grid">
                    <?php foreach ($wc_statuses as $slug => $label) : ?>
                    <label class="gf-checkbox-item">
                        <input type="checkbox" name="guardify_notification_statuses[]" value="<?php echo esc_attr($slug); ?>"
                            <?php checked(in_array($slug, $settings['notification_statuses'], true)); ?>
                            class="gf-setting-toggle" />
                        <span><?php echo esc_html($label); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="gf-card" style="margin-top: 1.5rem;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">SMS টেমপ্লেট</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">
                    ভেরিয়েবল: <code>{customer_name}</code>, <code>{order_number}</code>, <code>{product_name}</code>, <code>{order_total}</code>, <code>{order_date}</code>, <code>{siteurl}</code>
                </p>
                <?php foreach ($wc_statuses as $slug => $label) : ?>
                <div class="gf-form-group" style="margin-bottom: 1rem;">
                    <label class="gf-label"><?php echo esc_html($label); ?></label>
                    <textarea name="guardify_notification_templates[<?php echo esc_attr($slug); ?>]" class="gf-input gf-setting-input" rows="2" style="resize: vertical;"><?php echo esc_textarea(isset($templates[$slug]) ? $templates[$slug] : ''); ?></textarea>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tab: Connection -->
    <div class="gf-tab-content" id="gf-tab-connection" style="display: none;">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">সংযোগ ব্যবস্থাপনা</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">
                    সংযোগ বিচ্ছিন্ন করলে এই সাইটে Guardify Pro নিষ্ক্রিয় হবে।
                </p>
                <button id="gf-disconnect-btn" class="gf-btn gf-btn-danger">সংযোগ বিচ্ছিন্ন করুন</button>
            </div>
        </div>
    </div>

    <!-- Tab: Support -->
    <div class="gf-tab-content" id="gf-tab-support" style="display: none;">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">সাপোর্ট টিকেট পাঠান</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">সমস্যা বা প্রশ্ন থাকলে আমাদের জানান। আমরা শীঘ্রই উত্তর দেব।</p>
                <div class="gf-form">
                    <div class="gf-form-group" style="margin-bottom: 1rem;">
                        <label class="gf-label">বিষয় *</label>
                        <input type="text" id="gf-support-subject" class="gf-input" placeholder="আপনার সমস্যার বিষয়" />
                    </div>
                    <div class="gf-form-group" style="margin-bottom: 1rem;">
                        <label class="gf-label">বিস্তারিত *</label>
                        <textarea id="gf-support-message" class="gf-input" rows="4" placeholder="আপনার সমস্যা বিস্তারিত লিখুন..."></textarea>
                    </div>
                    <button type="button" id="gf-support-submit" class="gf-btn gf-btn-primary">টিকেট পাঠান</button>
                    <span id="gf-support-msg" style="display: none; margin-left: 1rem;" class="gf-text-muted"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab: Update -->
    <div class="gf-tab-content" id="gf-tab-update" style="display: none;">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">প্লাগইন আপডেট</h2>
            </div>
            <div class="gf-card-body">
                <table class="widefat" style="max-width: 480px; border: none; background: transparent;">
                    <tbody>
                        <tr>
                            <td style="padding: 6px 0; font-weight: 500; border: none;">বর্তমান ভার্সন</td>
                            <td style="border: none;"><code><?php echo esc_html(GUARDIFY_VERSION); ?></code></td>
                        </tr>
                        <tr>
                            <td style="padding: 6px 0; font-weight: 500; border: none;">গিটহাব রিপো</td>
                            <td style="border: none;"><a href="https://github.com/GuardifyPro/Guardify-Plugin/releases" target="_blank" rel="noopener">GuardifyPro/Guardify-Plugin</a></td>
                        </tr>
                    </tbody>
                </table>
                <p class="gf-text-muted" style="margin: 1.25rem 0 1rem;">
                    GitHub-এ নতুন রিলিজ পাবলিশ হলে WordPress ড্যাশবোর্ড থেকেই আপডেট নোটিফিকেশন পাবেন।
                    নিচে বাটনে ক্লিক করে তাৎক্ষণিক আপডেট চেক করতে পারেন।
                </p>
                <button type="button" id="gf-check-update-btn" class="gf-btn gf-btn-primary">আপডেট চেক করুন</button>
                <span id="gf-update-msg" style="display: none; margin-left: 1rem;"></span>
            </div>
        </div>
    </div>

    <!-- Save button (only for settings tabs) -->
    <div id="gf-save-wrap" style="margin-top: 1.5rem; display: flex; align-items: center; gap: 1rem;">
        <button id="gf-save-settings" class="gf-btn gf-btn-primary">সেটিংস সংরক্ষণ করুন</button>
        <span id="gf-save-msg" style="display: none;" class="gf-text-muted"></span>
    </div>

    <?php endif; ?>
</div>
