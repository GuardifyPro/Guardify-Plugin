<?php
/**
 * Guardify Pro — Database backup page.
 *
 * Backups go to the Guardify engine, so the page is useless without a
 * connection and says so before showing controls that cannot work.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$backup_instance = Guardify_Backup::get_instance();
$gf_can_move     = current_user_can('manage_options');
$gf_domain       = Guardify_Domain::current_domain();
$schedule_info   = $backup_instance->get_schedule_info();
$is_connected    = !empty(get_option('guardify_api_key', ''));
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title">ডাটাবেইজ ব্যাকআপ</h1>
                <p class="gf-page-desc">আপনার WooCommerce ডাটাবেইজের অটোমেটিক ব্যাকআপ ও এক ক্লিকে রিস্টোর।</p>
            </div>
        </div>
    </div>

    <?php if (!$is_connected) : ?>

    <div class="gf-card">
        <div class="gf-card-body">
            <div class="gf-empty-state">
                <div class="gf-empty-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1M2 8h12v9a3 3 0 01-3 3H5a3 3 0 01-3-3z"/></svg>
                </div>
                <p class="gf-empty-state-title">প্লাগইন সংযুক্ত নয়</p>
                <p class="gf-empty-state-desc">ব্যাকআপ Guardify সার্ভারে সংরক্ষিত হয়, তাই প্রথমে API কী দিয়ে সংযুক্ত করতে হবে।</p>
                <div class="gf-empty-state-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-pro')); ?>" class="gf-btn gf-btn-primary">সেটিংসে যান</a>
                </div>
            </div>
        </div>
    </div>

    <?php else : ?>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">ব্যাকআপ শিডিউল</h2>
                <p class="gf-card-desc">অটো ব্যাকআপ চালু থাকলে আপনাকে কিছু মনে রাখতে হবে না। রাতের দিকে সময় দিলে দোকানের ব্যস্ত সময়ে সাইট ধীর হবে না।</p>
            </div>
            <?php if ($schedule_info['next_run']) : ?>
            <div class="gf-card-header-actions">
                <span class="gf-badge gf-badge-primary">পরবর্তী রান: <?php echo esc_html($schedule_info['next_run']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-settings-list">
                <label class="gf-toggle-row">
                    <span class="gf-toggle-info">
                        <span class="gf-toggle-label">অটো ব্যাকআপ চালু করুন</span>
                        <span class="gf-toggle-desc">বন্ধ থাকলে ব্যাকআপ শুধু আপনি নিজে নিলে হবে — কোনো শিডিউল চলবে না।</span>
                    </span>
                    <span class="gf-switch">
                        <input type="checkbox" id="gf-backup-enabled" <?php checked($schedule_info['enabled'], 'yes'); ?> />
                        <span class="gf-switch-slider"></span>
                    </span>
                </label>
            </div>

            <div class="gf-form-grid">
                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-frequency">ফ্রিকোয়েন্সি</label>
                    <select id="gf-backup-frequency" class="gf-select">
                        <option value="every_6h" <?php selected($schedule_info['frequency'], 'every_6h'); ?>>প্রতি ৬ ঘণ্টায়</option>
                        <option value="every_12h" <?php selected($schedule_info['frequency'], 'every_12h'); ?>>প্রতি ১২ ঘণ্টায়</option>
                        <option value="daily" <?php selected($schedule_info['frequency'], 'daily'); ?>>প্রতিদিন</option>
                        <option value="weekly" <?php selected($schedule_info['frequency'], 'weekly'); ?>>প্রতি সপ্তাহে</option>
                    </select>
                    <span class="gf-help">দিনে অনেক অর্ডার এলে ৬ বা ১২ ঘণ্টা বেছে নিন।</span>
                </div>

                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-time">সময় (২৪ ঘণ্টা ফরম্যাট)</label>
                    <input type="time" id="gf-backup-time" class="gf-input" value="<?php echo esc_attr($schedule_info['time']); ?>" />
                    <span class="gf-help">দিনের কোন সময়ে ব্যাকআপ শুরু হবে।</span>
                </div>

                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-timezone">টাইমজোন</label>
                    <select id="gf-backup-timezone" class="gf-select">
                        <option value="Asia/Dhaka" <?php selected($schedule_info['timezone'], 'Asia/Dhaka'); ?>>Asia/Dhaka (বাংলাদেশ)</option>
                        <option value="Asia/Kolkata" <?php selected($schedule_info['timezone'], 'Asia/Kolkata'); ?>>Asia/Kolkata (ভারত)</option>
                        <option value="UTC" <?php selected($schedule_info['timezone'], 'UTC'); ?>>UTC</option>
                    </select>
                    <span class="gf-help">উপরের সময়টি কোন টাইমজোনে ধরা হবে।</span>
                </div>
            </div>
        </div>
        <div class="gf-card-footer">
            <button type="button" id="gf-save-schedule" class="gf-btn gf-btn-primary">শিডিউল সেভ করুন</button>
        </div>
    </div>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">ম্যানুয়াল ব্যাকআপ</h2>
                <p class="gf-card-desc">বড় কোনো পরিবর্তনের আগে — নতুন প্লাগইন, থিম আপডেট, দাম পরিবর্তন — এখনই একটি ব্যাকআপ নিয়ে রাখুন।</p>
            </div>
        </div>
        <div class="gf-card-body">
            <div class="gf-row">
                <button type="button" id="gf-backup-now" class="gf-btn gf-btn-secondary">এখনই ব্যাকআপ নিন</button>
                <span id="gf-backup-status" class="gf-help"></span>
            </div>
        </div>
    </div>

    <div class="gf-card gf-card-danger">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">রিস্টোর</h2>
                <p class="gf-card-desc">
                    আগের কোনো ব্যাকআপ থেকে ডাটাবেইজ ফিরিয়ে আনুন। রিস্টোর শুরু করতে হয় Guardify
                    ড্যাশবোর্ড থেকে — সেখানে আমরা ফাইলটি পুরোপুরি যাচাই করে একটি কোড দিই।
                </p>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                    <strong class="gf-alert-title">আপনার সাইট চালু থাকবে</strong>
                    Guardify নতুন ডাটা আলাদা টেবিলে তৈরি করে, তারপর এক ধাপে বদলে দেয়। মাঝপথে কিছু ভুল হলে
                    আপনার বর্তমান ডাটাবেইজে কোনো পরিবর্তন হবে না।
                </div>
            </div>

            <div class="gf-alert gf-alert-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
                <div>
                    <strong class="gf-alert-title">ব্যাকআপের পরের অর্ডারগুলো থাকবে না</strong>
                    যে সময়ের ব্যাকআপ ফেরাচ্ছেন, তার পরে আসা অর্ডার ও পরিবর্তন মুছে যাবে।
                </div>
            </div>

            <div id="gf-restore-live" class="gf-hidden"></div>

            <div class="gf-field gf-field-wide">
                <label class="gf-label" for="gf-restore-token">রিস্টোর কোড</label>
                <input type="text" id="gf-restore-token" class="gf-input gf-input-mono"
                       autocomplete="off" spellcheck="false" placeholder="Guardify ড্যাশবোর্ড থেকে পাওয়া কোড">
                <span class="gf-help">
                    <a href="https://guardify.pro/backups" target="_blank" rel="noopener">ড্যাশবোর্ডে যান</a> —
                    ব্যাকআপ বেছে নিয়ে সাইটের ডোমেইন লিখে নিশ্চিত করলে কোডটি পাবেন। কোড ৩০ মিনিট পর্যন্ত বৈধ।
                </span>
            </div>

            <div id="gf-restore-status"></div>

            <div class="gf-row">
                <button type="button" id="gf-restore-btn" class="gf-btn gf-btn-danger">রিস্টোর শুরু করুন</button>
                <button type="button" id="gf-restore-abort" class="gf-btn gf-btn-ghost gf-hidden">বাতিল করুন</button>
            </div>

            <div id="gf-backup-list-container">
                <div class="gf-loading">
                    <span class="gf-spinner" aria-hidden="true"></span>
                    ব্যাকআপ লোড হচ্ছে…
                </div>
            </div>
        </div>
    </div>

    <?php if ($gf_can_move) : ?>
    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">ডোমেইন পরিবর্তন</h2>
                <p class="gf-card-desc">
                    সাইট নতুন ডোমেইনে নিয়ে যাচ্ছেন? এক ক্লিকে সব ঠিকানা বদলে যাবে — পোস্ট, পণ্যের ছবি,
                    থিম ও প্লাগইনের সেটিংস, সবকিছু।
                </p>
            </div>
            <div class="gf-card-header-actions">
                <span class="gf-badge gf-badge-muted gf-mono"><?php echo esc_html($gf_domain); ?></span>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                    <strong class="gf-alert-title">সাধারণ সার্চ-রিপ্লেস প্লাগইনের মতো নয়</strong>
                    WordPress অনেক সেটিংস এমন ফরম্যাটে রাখে যেখানে লেখার দৈর্ঘ্যও সংরক্ষিত থাকে। সাধারণ
                    রিপ্লেস করলে ওই হিসাব ভেঙে যায় আর উইজেট, থিম ও পেমেন্ট সেটিংস চুপচাপ মুছে যায় —
                    কোনো এরর ছাড়াই। Guardify কাজটি নিজের সার্ভারে করে, সঠিক হিসাব রেখে।
                </div>
            </div>

            <div id="gf-domain-live" class="gf-hidden"></div>

            <div class="gf-field gf-field-wide" id="gf-domain-input-wrap">
                <label class="gf-label" for="gf-domain-new">নতুন ডোমেইন</label>
                <input type="text" id="gf-domain-new" class="gf-input gf-input-mono"
                       autocomplete="off" spellcheck="false" placeholder="newshop.com.bd">
                <span class="gf-help">
                    শুধু ঠিকানাটি লিখুন — https:// বা www. লেখার দরকার নেই। শুরু করার আগে নিশ্চিত করুন
                    নতুন ডোমেইনটি এই সাইটের দিকেই পয়েন্ট করা আছে।
                </span>
            </div>

            <div class="gf-alert gf-alert-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
                <div>
                    <strong class="gf-alert-title">শেষ হলে আপনাকে নতুন ঠিকানায় লগইন করতে হবে</strong>
                    পরিবর্তনের সময় সাইট চালু থাকবে। শেষ ধাপে ঠিকানা বদলে যাবে, তাই তখন
                    পুরোনো ঠিকানায় wp-admin আর খুলবে না।
                </div>
            </div>

            <div id="gf-domain-status"></div>

            <div class="gf-row">
                <button type="button" id="gf-domain-start" class="gf-btn gf-btn-primary">ডোমেইন পরিবর্তন করুন</button>
                <button type="button" id="gf-domain-cancel" class="gf-btn gf-btn-ghost gf-hidden">বাতিল করুন</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
jQuery(function ($) {
    var ajaxUrl = guardifyData.ajaxUrl;
    var nonce   = guardifyData.nonce;
    var GF      = window.Guardify;

    /* ── Backup list ──────────────────────────────────────────────────── */

    function loadBackups() {
        $.post(ajaxUrl, { action: 'guardify_backup_list', _ajax_nonce: nonce }, function (res) {
            var $container = $('#gf-backup-list-container').empty();

            if (!res.success || !res.data) {
                $container.append($('<p>').addClass('gf-help').text('ব্যাকআপ লোড করা যায়নি।'));
                return;
            }

            var backups = res.data.backups || [];
            var count   = res.data.count || 0;

            if (!backups.length) {
                $container.append(
                    $('<p>').addClass('gf-help').text('কোনো ব্যাকআপ নেই। উপরের বাটনে ক্লিক করে প্রথম ব্যাকআপ নিন।')
                );
                return;
            }

            var $list = $('<ul>').addClass('gf-kv');
            backups.slice(0, 10).forEach(function (b) {
                var d     = new Date(b.created_at);
                var label = d.toLocaleDateString('bn-BD', {
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                var size  = (b.file_size / 1024 / 1024).toFixed(2) + ' MB';
                // Built with the DOM API rather than string concatenation: a backup note is
                // free text a merchant typed, and one containing markup would otherwise be
                // rendered as markup.
                $list.append($('<li>').append(
                    $('<span>').text(label),
                    $('<span>').addClass('gf-text-muted').text(size + (b.note ? ' — ' + b.note : ''))
                ));
            });

            $container.append(
                $('<p>').addClass('gf-label').text('সংরক্ষিত ব্যাকআপ'),
                $list,
                $('<p>').addClass('gf-help').text(
                    'মোট ' + count + ' টি ব্যাকআপ আছে। কোনটি ফেরাবেন তা Guardify ড্যাশবোর্ড থেকে বেছে নিন।'
                )
            );
        });
    }

    loadBackups();

    /* ── Manual backup ────────────────────────────────────────────────── */

    /*
     * The dump runs in the background in bounded slices, so this button only queues it.
     * Holding an admin-ajax request open for the length of a database dump would hit the
     * PHP timeout on any large shop and report a failure for a backup that was still
     * running perfectly well — so progress is polled instead.
     */
    var pollTimer = null;

    function pollBackup($btn, $status) {
        $.post(ajaxUrl, { action: 'guardify_backup_status', _ajax_nonce: nonce }, function (res) {
            if (!res.success) { return; }

            if (res.data.running) {
                var pct = res.data.percent || 0;
                $status.removeClass('gf-success gf-error')
                    .text('ব্যাকআপ চলছে… ' + pct + '%');
                pollTimer = setTimeout(function () { pollBackup($btn, $status); }, 3000);
                return;
            }

            GF.setLoading($btn, false);

            var last = res.data.last;
            if (last && last.ok) {
                $status.addClass('gf-success').text(last.message || 'ব্যাকআপ সম্পন্ন হয়েছে।');
                GF.toast(last.message || 'ব্যাকআপ সম্পন্ন হয়েছে।', { type: 'success' });
                loadBackups();
            } else if (last) {
                $status.addClass('gf-error').text(last.message || 'ব্যাকআপ ব্যর্থ হয়েছে।');
            } else {
                $status.text('');
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            $status.addClass('gf-error').text('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
        });
    }

    $('#gf-backup-now').on('click', function () {
        var $btn = $(this);
        var $status = $('#gf-backup-status').removeClass('gf-success gf-error').text('');

        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        GF.setLoading($btn, true);

        $.post(ajaxUrl, { action: 'guardify_backup_now', _ajax_nonce: nonce }, function (res) {
            if (res.success) {
                $status.text(res.data.message || 'ব্যাকআপ শুরু হয়েছে।');
                pollTimer = setTimeout(function () { pollBackup($btn, $status); }, 2000);
            } else {
                GF.setLoading($btn, false);
                $status.addClass('gf-error').text(res.data || 'ব্যাকআপ ব্যর্থ হয়েছে।');
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            $status.addClass('gf-error').text('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
        });
    });

    // A dump queued in an earlier page view is still running; pick its progress back up
    // rather than showing an idle screen while the site is mid-backup.
    (function resumeIfRunning() {
        $.post(ajaxUrl, { action: 'guardify_backup_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.running) {
                var $btn = $('#gf-backup-now');
                GF.setLoading($btn, true);
                pollBackup($btn, $('#gf-backup-status'));
            }
        });
    })();

    /* ── Restore ──────────────────────────────────────────────────────── */

    /*
     * The plugin never decides that a restore may happen — it only carries one out. The
     * archive is verified end to end on Guardify's side and the target domain is confirmed
     * there, which is both a safety property and the reason a 400MB integrity check does
     * not run on the merchant's hosting.
     */
    var restoreTimer = null;

    function restoreNotice(type, text) {
        $('#gf-restore-status').html($('<div>').addClass('gf-alert gf-alert-' + type).text(text));
    }

    function pollRestore() {
        $.post(ajaxUrl, { action: 'guardify_restore_status', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { return; }

                if (res.data.running) {
                    var line = res.data.message;
                    if (res.data.stage === 'download' && res.data.expected > 0) {
                        line += ' ' + Math.round((res.data.downloaded / res.data.expected) * 100) + '%';
                    }
                    $('#gf-restore-live').removeClass('gf-hidden')
                        .html($('<div>').addClass('gf-alert gf-alert-info').text(line));
                    $('#gf-restore-abort').removeClass('gf-hidden');
                    restoreTimer = setTimeout(pollRestore, 4000);
                    return;
                }

                $('#gf-restore-live').addClass('gf-hidden').empty();
                $('#gf-restore-abort').addClass('gf-hidden');
                GF.setLoading($('#gf-restore-btn'), false);

                var last = res.data.last;
                if (last && last.ok) {
                    restoreNotice('success', last.message);
                    GF.toast(last.message, { type: 'success' });
                    loadBackups();
                } else if (last) {
                    restoreNotice('error', last.message);
                }
            })
            .fail(function () {
                GF.setLoading($('#gf-restore-btn'), false);
                restoreNotice('error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
            });
    }

    $('#gf-restore-btn').on('click', function () {
        var $btn  = $(this);
        var token = $.trim($('#gf-restore-token').val());

        if (!/^[a-f0-9]{64}$/i.test(token)) {
            restoreNotice('error', 'কোডটি সঠিক নয়। Guardify ড্যাশবোর্ড থেকে কোডটি কপি করে বসান।');
            return;
        }

        if (!confirm('আপনি কি নিশ্চিত?\n\nএই ব্যাকআপের পরে আসা সব অর্ডার ও পরিবর্তন মুছে যাবে। নতুন ডাটা প্রস্তুত না হওয়া পর্যন্ত আপনার সাইট স্বাভাবিকভাবে চলবে।')) {
            return;
        }

        if (restoreTimer) { clearTimeout(restoreTimer); restoreTimer = null; }
        $('#gf-restore-status').empty();
        GF.setLoading($btn, true);

        $.post(ajaxUrl, { action: 'guardify_restore_start', token: token, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    GF.setLoading($btn, false);
                    restoreNotice('error', res.data || 'রিস্টোর শুরু করা যায়নি।');
                    return;
                }
                $('#gf-restore-token').val('');
                restoreNotice('info', res.data.message);
                restoreTimer = setTimeout(pollRestore, 2000);
            })
            .fail(function () {
                GF.setLoading($btn, false);
                restoreNotice('error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
            });
    });

    $('#gf-restore-abort').on('click', function () {
        if (!confirm('রিস্টোর বাতিল করবেন? আপনার সাইটে কোনো পরিবর্তন হয়নি।')) { return; }
        $.post(ajaxUrl, { action: 'guardify_restore_abort', _ajax_nonce: nonce })
            .always(function () {
                if (restoreTimer) { clearTimeout(restoreTimer); restoreTimer = null; }
                pollRestore();
            });
    });

    // A restore queued in an earlier page view is still running; pick it back up rather
    // than showing an idle screen while the database is mid-swap.
    (function resumeRestore() {
        $.post(ajaxUrl, { action: 'guardify_restore_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.running) {
                GF.setLoading($('#gf-restore-btn'), true);
                pollRestore();
            }
        });
    })();

    /* ── Domain change ────────────────────────────────────────────────── */

    /*
     * One click, three stages the merchant never has to think about: a fresh backup, the
     * engine rewriting every URL in it, and a restore that swaps the result in atomically.
     * Polling drives it because each stage is already a sliced background job — this only
     * asks whether the current one has finished.
     */
    var domainTimer = null;

    function domainNotice(type, text) {
        $('#gf-domain-status').html($('<div>').addClass('gf-alert gf-alert-' + type).text(text));
    }

    function domainRunning(running) {
        $('#gf-domain-input-wrap').toggleClass('gf-hidden', running);
        $('#gf-domain-cancel').toggleClass('gf-hidden', !running);
        GF.setLoading($('#gf-domain-start'), running);
    }

    function pollDomain() {
        $.post(ajaxUrl, { action: 'guardify_domain_advance', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    domainRunning(false);
                    domainNotice('error', res.data || 'ডোমেইন পরিবর্তন ব্যর্থ হয়েছে।');
                    return;
                }

                var d = res.data;

                if (d.error) {
                    domainRunning(false);
                    $('#gf-domain-live').addClass('gf-hidden').empty();
                    domainNotice('error', d.error);
                    return;
                }

                if (d.done) {
                    domainRunning(false);
                    $('#gf-domain-live').addClass('gf-hidden').empty();
                    domainNotice('success',
                        'ডোমেইন পরিবর্তন সম্পন্ন হয়েছে। এখন থেকে সাইটটি ' + d.new + ' ঠিকানায় চলবে।');
                    // The old address no longer serves wp-admin, so staying here would show
                    // a screen whose every subsequent request fails. Sent on rather than
                    // left to discover it.
                    setTimeout(function () {
                        window.location.href = 'https://' + d.new + '/wp-admin/admin.php?page=guardify-backup';
                    }, 2500);
                    return;
                }

                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(d.message));
                domainTimer = setTimeout(pollDomain, 5000);
            })
            .fail(function () {
                // A failure here is usually the site having just moved: the browser is
                // still on the old address and admin-ajax is answering from the new one.
                domainRunning(false);
                domainNotice('warning',
                    'সংযোগ বিচ্ছিন্ন হয়েছে — সম্ভবত সাইটটি নতুন ঠিকানায় চলে গেছে। নতুন ঠিকানায় গিয়ে দেখুন।');
            });
    }

    $('#gf-domain-start').on('click', function () {
        var newDomain = $.trim($('#gf-domain-new').val());

        if (!newDomain) {
            domainNotice('error', 'নতুন ডোমেইনটি লিখুন।');
            return;
        }

        if (!confirm('আপনি কি নিশ্চিত?\n\nসাইটের সব ঠিকানা ' + newDomain + ' এ বদলে যাবে। ' +
                     'পরিবর্তনের সময় সাইট চালু থাকবে, তবে শেষ হলে আপনাকে নতুন ঠিকানায় লগইন করতে হবে।')) {
            return;
        }

        if (domainTimer) { clearTimeout(domainTimer); domainTimer = null; }
        $('#gf-domain-status').empty();
        domainRunning(true);

        $.post(ajaxUrl, { action: 'guardify_domain_start', new_domain: newDomain, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    domainRunning(false);
                    domainNotice('error', res.data || 'শুরু করা যায়নি।');
                    return;
                }
                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                domainTimer = setTimeout(pollDomain, 4000);
            })
            .fail(function () {
                domainRunning(false);
                domainNotice('error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
            });
    });

    $('#gf-domain-cancel').on('click', function () {
        if (!confirm('ডোমেইন পরিবর্তন বাতিল করবেন? আপনার সাইটে কোনো পরিবর্তন হয়নি।')) { return; }

        if (domainTimer) { clearTimeout(domainTimer); domainTimer = null; }

        $.post(ajaxUrl, { action: 'guardify_domain_cancel', _ajax_nonce: nonce })
            .done(function (res) {
                domainRunning(false);
                $('#gf-domain-live').addClass('gf-hidden').empty();
                domainNotice('info', (res.data && res.data.message) || 'বাতিল হয়েছে।');
            });
    });

    // A change started before this page was loaded — a closed browser, a reload — is picked
    // back up rather than appearing not to be happening.
    if ($('#gf-domain-start').length) {
        $.post(ajaxUrl, { action: 'guardify_domain_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.stage && res.data.stage !== 'failed') {
                domainRunning(true);
                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                domainTimer = setTimeout(pollDomain, 3000);
            } else if (res.success && res.data.error) {
                domainNotice('error', res.data.error);
            }
        });
    }


    /* ── Schedule ─────────────────────────────────────────────────────── */

    $('#gf-save-schedule').on('click', function () {
        var $btn = $(this);
        GF.setLoading($btn, true);

        $.post(ajaxUrl, {
            action: 'guardify_backup_save_schedule',
            _ajax_nonce: nonce,
            guardify_backup_enabled: $('#gf-backup-enabled').is(':checked') ? 'yes' : 'no',
            guardify_backup_frequency: $('#gf-backup-frequency').val(),
            guardify_backup_time: $('#gf-backup-time').val(),
            guardify_backup_timezone: $('#gf-backup-timezone').val()
        }, function (res) {
            if (res.success) {
                GF.toast(res.data.message || 'শিডিউল সেভ হয়েছে।', { type: 'success' });
            } else {
                GF.toast(res.data || 'শিডিউল সেভ করা যায়নি।', { type: 'error' });
            }
        }).fail(function () {
            GF.toast('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।', { type: 'error' });
        }).always(function () {
            GF.setLoading($btn, false);
        });
    });
});
</script>
