<?php
defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$api       = new Guardify_API();
$connected = $api->is_connected();
$api_key   = get_option('guardify_api_key', '');
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

    <!-- Disconnect -->
    <div class="gf-card" style="margin-top: 1.5rem;">
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
    <?php endif; ?>
</div>
