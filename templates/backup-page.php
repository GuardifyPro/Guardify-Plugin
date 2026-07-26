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
                <p class="gf-card-desc">আগের কোনো ব্যাকআপ থেকে ডাটাবেইজ ফিরিয়ে আনুন।</p>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
                <div>
                    <strong class="gf-alert-title">রিস্টোর বর্তমান ডাটাবেইজ প্রতিস্থাপন করে</strong>
                    ব্যাকআপের সময়ের পরে আসা সব অর্ডার ও পরিবর্তন হারিয়ে যাবে। নিরাপত্তার জন্য রিস্টোর শুরুর আগে বর্তমান অবস্থার একটি ব্যাকআপ স্বয়ংক্রিয়ভাবে নেওয়া হবে।
                </div>
            </div>

            <div id="gf-backup-list-container">
                <div class="gf-loading">
                    <span class="gf-spinner" aria-hidden="true"></span>
                    ব্যাকআপ লোড হচ্ছে…
                </div>
            </div>
        </div>
    </div>

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

            var $select = $('<select>')
                .attr({ id: 'gf-restore-select', 'aria-label': 'ব্যাকআপ বাছুন' })
                .addClass('gf-select');

            // Built with the DOM API rather than string concatenation: a
            // backup note is free text a merchant typed, and one that happens
            // to contain a quote would otherwise break out of the option.
            backups.forEach(function (b) {
                var d     = new Date(b.created_at);
                var label = d.toLocaleDateString('bn-BD', {
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                var size  = (b.file_size / 1024 / 1024).toFixed(2) + ' MB';
                var text  = label + ' (' + size + ')' + (b.note ? ' — ' + b.note : '');
                $select.append($('<option>').val(b.id).text(text));
            });

            $container.append(
                $('<div>').addClass('gf-field gf-mb-2').append(
                    $('<label>').addClass('gf-label').attr('for', 'gf-restore-select')
                        .text('কোন ব্যাকআপ থেকে ফিরবেন'),
                    $select,
                    $('<span>').addClass('gf-help').text('মোট ' + count + ' টি ব্যাকআপ আছে। সবচেয়ে নতুনটি উপরে।')
                ),
                $('<div>').addClass('gf-row').append(
                    $('<button>').attr('type', 'button').attr('id', 'gf-restore-btn')
                        .addClass('gf-btn gf-btn-danger').text('রিস্টোর করুন'),
                    $('<span>').attr('id', 'gf-restore-status').addClass('gf-help')
                )
            );
        });
    }

    loadBackups();

    /* ── Manual backup ────────────────────────────────────────────────── */

    $('#gf-backup-now').on('click', function () {
        var $btn = $(this);
        var $status = $('#gf-backup-status').removeClass('gf-success gf-error').text('');

        GF.setLoading($btn, true);

        $.post(ajaxUrl, { action: 'guardify_backup_now', _ajax_nonce: nonce }, function (res) {
            if (res.success) {
                $status.addClass('gf-success').text(res.data.message || 'ব্যাকআপ সম্পন্ন হয়েছে।');
                GF.toast(res.data.message || 'ব্যাকআপ সম্পন্ন হয়েছে।', { type: 'success' });
                loadBackups();
            } else {
                $status.addClass('gf-error').text(res.data || 'ব্যাকআপ ব্যর্থ হয়েছে।');
            }
        }).fail(function () {
            $status.addClass('gf-error').text('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
        }).always(function () {
            GF.setLoading($btn, false);
        });
    });

    /* ── Restore ──────────────────────────────────────────────────────── */

    $(document).on('click', '#gf-restore-btn', function () {
        var backupId = $('#gf-restore-select').val();
        if (!backupId) { return; }

        if (!confirm('আপনি কি নিশ্চিত?\n\nএই ব্যাকআপ থেকে রিস্টোর করলে বর্তমান ডাটাবেইজ প্রতিস্থাপিত হবে। রিস্টোরের আগে একটি নিরাপত্তা ব্যাকআপ নেওয়া হবে।')) {
            return;
        }

        var $btn = $(this);
        var $status = $('#gf-restore-status').removeClass('gf-success gf-error').text('');

        GF.setLoading($btn, true);

        $.post(ajaxUrl, {
            action: 'guardify_backup_restore',
            _ajax_nonce: nonce,
            backup_id: backupId
        }, function (res) {
            if (res.success) {
                $status.addClass('gf-success').text(res.data.message || 'রিস্টোর সম্পন্ন হয়েছে।');
                GF.toast(res.data.message || 'রিস্টোর সম্পন্ন হয়েছে।', { type: 'success' });
                loadBackups();
            } else {
                $status.addClass('gf-error').text(res.data || 'রিস্টোর ব্যর্থ হয়েছে।');
            }
        }).fail(function () {
            $status.addClass('gf-error').text('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।');
        }).always(function () {
            GF.setLoading($btn, false);
        });
    });

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
