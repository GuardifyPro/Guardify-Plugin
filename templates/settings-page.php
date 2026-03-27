<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$api       = new Guardify_API();
$connected = $api->is_connected();
$api_key   = get_option('guardify_api_key', '');

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
    'fraud_detection_enabled'     => get_option('guardify_fraud_detection_enabled', 'no'),
    'fraud_auto_block_dp'         => get_option('guardify_fraud_auto_block_dp', 0),
    'sms_notifications_enabled'   => get_option('guardify_sms_notifications_enabled', 'no'),
    'notification_statuses'       => get_option('guardify_notification_statuses', []),
    'notification_templates'      => get_option('guardify_notification_templates', []),
    'incomplete_orders_enabled'   => get_option('guardify_incomplete_orders_enabled', 'no'),
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
            <h2 class="gf-card-title">API কী সংযুক্ত করুন</h2>
        </div>
        <div class="gf-card-body">
            <p class="gf-text-muted" style="margin-bottom: 1.25rem;">
                <a href="https://guardify.pro" target="_blank" rel="noopener">guardify.pro</a> থেকে API Key ও Secret Key সংগ্রহ করুন।
            </p>
            <form id="gf-connect-form" class="gf-form">
                <div class="gf-form-row">
                    <div class="gf-form-group">
                        <label class="gf-label">API Key</label>
                        <input type="text" id="gf-api-key" class="gf-input" placeholder="gp_..." required />
                    </div>
                    <div class="gf-form-group">
                        <label class="gf-label">Secret Key</label>
                        <input type="password" id="gf-secret-key" class="gf-input" placeholder="sk_..." required />
                    </div>
                </div>
                <button type="submit" class="gf-btn gf-btn-primary" id="gf-connect-btn">
                    সংযুক্ত করুন
                </button>
            </form>
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
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">API Key</p>
                <p class="gf-stat-value" style="font-size: 0.875rem; word-break: break-all;"><?php echo esc_html($api_key); ?></p>
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
    </div>

    <!-- Tabs -->
    <div class="gf-tabs" style="margin-top: 2rem;">
        <button class="gf-tab active" data-tab="features">ফিচার সমূহ</button>
        <button class="gf-tab" data-tab="smart-filter">স্মার্ট ফিল্টার</button>
        <button class="gf-tab" data-tab="notifications">SMS নোটিফিকেশন</button>
        <button class="gf-tab" data-tab="connection">সংযোগ</button>
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
                    ভেরিয়েবল: <code>{customer_name}</code>, <code>{order_id}</code>, <code>{order_total}</code>, <code>{site_name}</code>, <code>{status}</code>
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

    <!-- Save button (for all tabs) -->
    <div style="margin-top: 1.5rem; display: flex; align-items: center; gap: 1rem;">
        <button id="gf-save-settings" class="gf-btn gf-btn-primary">সেটিংস সংরক্ষণ করুন</button>
        <span id="gf-save-msg" style="display: none;" class="gf-text-muted"></span>
    </div>

    <?php endif; ?>
</div>
