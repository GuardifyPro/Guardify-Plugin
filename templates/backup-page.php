<?php
defined('ABSPATH') || exit;

$backup_instance = Guardify_Backup::get_instance();
$schedule_info = $backup_instance->get_schedule_info();
$is_connected = !empty(get_option('guardify_api_key', ''));
?>
<div class="wrap gf-wrap">
    <h1 class="gf-page-title">
        <span class="dashicons dashicons-database-export" style="margin-right: 8px;"></span>
        ডাটাবেইজ ব্যাকআপ
    </h1>
    <p class="gf-page-desc">আপনার WooCommerce সাইটের ডাটাবেইজ অটোমেটিক ব্যাকআপ ও ইজি রিস্টোর।</p>

    <?php if (!$is_connected) : ?>
        <div class="gf-card" style="border-left: 4px solid #ef4444;">
            <p style="color: #ef4444; font-weight: 600;">⚠ প্লাগইন সংযুক্ত নয়। ব্যাকআপ ব্যবহার করতে সেটিংস পেজ থেকে API কী সংযুক্ত করুন।</p>
        </div>
    <?php else : ?>

    <!-- Schedule Settings -->
    <div class="gf-card">
        <h3 class="gf-card-title">📅 ব্যাকআপ শিডিউল</h3>
        <div class="gf-form-grid" style="gap: 16px; margin-bottom: 16px;">
            <div class="gf-field">
                <label for="gf-backup-enabled">
                    <input type="checkbox" id="gf-backup-enabled" <?php checked($schedule_info['enabled'], 'yes'); ?>>
                    অটো ব্যাকআপ চালু করুন
                </label>
            </div>

            <div class="gf-field">
                <label for="gf-backup-frequency">ফ্রিকোয়েন্সি</label>
                <select id="gf-backup-frequency" class="gf-select">
                    <option value="every_6h" <?php selected($schedule_info['frequency'], 'every_6h'); ?>>প্রতি ৬ ঘণ্টায়</option>
                    <option value="every_12h" <?php selected($schedule_info['frequency'], 'every_12h'); ?>>প্রতি ১২ ঘণ্টায়</option>
                    <option value="daily" <?php selected($schedule_info['frequency'], 'daily'); ?>>প্রতিদিন</option>
                    <option value="weekly" <?php selected($schedule_info['frequency'], 'weekly'); ?>>প্রতি সপ্তাহে</option>
                </select>
            </div>

            <div class="gf-field">
                <label for="gf-backup-time">সময় (২৪ ঘণ্টা ফরম্যাট)</label>
                <input type="time" id="gf-backup-time" class="gf-input" value="<?php echo esc_attr($schedule_info['time']); ?>">
            </div>

            <div class="gf-field">
                <label for="gf-backup-timezone">টাইমজোন</label>
                <select id="gf-backup-timezone" class="gf-select">
                    <option value="Asia/Dhaka" <?php selected($schedule_info['timezone'], 'Asia/Dhaka'); ?>>Asia/Dhaka (বাংলাদেশ)</option>
                    <option value="Asia/Kolkata" <?php selected($schedule_info['timezone'], 'Asia/Kolkata'); ?>>Asia/Kolkata (ভারত)</option>
                    <option value="UTC" <?php selected($schedule_info['timezone'], 'UTC'); ?>>UTC</option>
                </select>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 12px;">
            <button type="button" id="gf-save-schedule" class="gf-btn gf-btn-primary">
                <span class="dashicons dashicons-saved" style="margin-right: 4px;"></span>
                শিডিউল সেভ করুন
            </button>
            <?php if ($schedule_info['next_run']) : ?>
                <span class="gf-text-muted" style="font-size: 13px;">
                    পরবর্তী রান: <?php echo esc_html($schedule_info['next_run']); ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Manual Backup -->
    <div class="gf-card">
        <h3 class="gf-card-title">💾 ম্যানুয়াল ব্যাকআপ</h3>
        <p class="gf-text-muted" style="margin-bottom: 12px;">এখনই আপনার ডাটাবেইজের একটি ব্যাকআপ নিন।</p>
        <button type="button" id="gf-backup-now" class="gf-btn gf-btn-primary">
            <span class="dashicons dashicons-database-export" style="margin-right: 4px;"></span>
            এখনই ব্যাকআপ নিন
        </button>
        <span id="gf-backup-status" class="gf-text-muted" style="margin-left: 12px;"></span>
    </div>

    <!-- Backup List & Restore -->
    <div class="gf-card">
        <h3 class="gf-card-title">🔄 রিস্টোর</h3>
        <p class="gf-text-muted" style="margin-bottom: 12px;">
            আগের একটি ব্যাকআপ থেকে আপনার ডাটাবেইজ পুনরুদ্ধার করুন।
            <strong>সতর্কতা:</strong> রিস্টোর করলে বর্তমান ডাটাবেইজ প্রতিস্থাপিত হবে। রিস্টোরের আগে স্বয়ংক্রিয়ভাবে একটি ব্যাকআপ নেওয়া হবে।
        </p>

        <div id="gf-backup-list-container">
            <div class="gf-loading" style="padding: 20px; text-align: center;">
                <span class="spinner is-active" style="float: none;"></span> ব্যাকআপ লোড হচ্ছে...
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<style>
.gf-wrap { max-width: 800px; }
.gf-page-title { font-size: 22px; font-weight: 700; display: flex; align-items: center; margin-bottom: 4px; }
.gf-page-desc { color: #64748b; margin-bottom: 20px; }
.gf-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-bottom: 16px; }
.gf-card-title { font-size: 16px; font-weight: 600; margin: 0 0 12px; }
.gf-form-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
.gf-field { display: flex; flex-direction: column; gap: 4px; }
.gf-field label { font-size: 13px; font-weight: 500; color: #374151; }
.gf-input, .gf-select { padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.gf-input:focus, .gf-select:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 2px rgba(99,102,241,0.15); }
.gf-btn { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 6px; font-size: 14px; font-weight: 500; cursor: pointer; border: none; transition: all 0.15s; }
.gf-btn-primary { background: #6366f1; color: #fff; }
.gf-btn-primary:hover { background: #4f46e5; }
.gf-btn-primary:disabled { background: #a5b4fc; cursor: not-allowed; }
.gf-btn-danger { background: #ef4444; color: #fff; }
.gf-btn-danger:hover { background: #dc2626; }
.gf-btn-danger:disabled { background: #fca5a5; cursor: not-allowed; }
.gf-text-muted { color: #64748b; font-size: 13px; }
.gf-backup-count { font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 12px; }
.gf-restore-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.gf-restore-select { flex: 1; min-width: 250px; }
.gf-success { color: #16a34a; font-weight: 500; }
.gf-error { color: #ef4444; font-weight: 500; }
</style>

<script>
jQuery(function($) {
    var ajaxUrl = guardifyData.ajaxUrl;
    var nonce   = guardifyData.nonce;

    // Load backup list
    function loadBackups() {
        $.post(ajaxUrl, { action: 'guardify_backup_list', _ajax_nonce: nonce }, function(res) {
            var container = $('#gf-backup-list-container');
            if (!res.success || !res.data) {
                container.html('<p class="gf-text-muted">ব্যাকআপ লোড করা যায়নি।</p>');
                return;
            }

            var data    = res.data;
            var backups = data.backups || [];
            var count   = data.count || 0;

            var html = '<p class="gf-backup-count">মোট ব্যাকআপ: <strong>' + count + '</strong></p>';

            if (backups.length === 0) {
                html += '<p class="gf-text-muted">কোনো ব্যাকআপ নেই। উপরের বাটনে ক্লিক করে প্রথম ব্যাকআপ নিন।</p>';
            } else {
                html += '<div class="gf-restore-row">';
                html += '<select id="gf-restore-select" class="gf-select gf-restore-select">';
                backups.forEach(function(b) {
                    var d    = new Date(b.created_at);
                    var label = d.toLocaleDateString('bn-BD', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                    var size  = (b.file_size / 1024 / 1024).toFixed(2) + ' MB';
                    var note  = b.note ? ' — ' + $('<span>').text(b.note).html() : '';
                    var safeId = $('<span>').text(b.id).html();
                    html += '<option value="' + safeId + '">' + label + ' (' + size + ')' + note + '</option>';
                });
                html += '</select>';
                html += '<button type="button" id="gf-restore-btn" class="gf-btn gf-btn-danger">রিস্টোর করুন</button>';
                html += '</div>';
                html += '<span id="gf-restore-status" class="gf-text-muted" style="margin-top: 8px; display: block;"></span>';
            }

            container.html(html);
        });
    }

    loadBackups();

    // Manual backup
    $('#gf-backup-now').on('click', function() {
        var btn = $(this);
        var status = $('#gf-backup-status');
        btn.prop('disabled', true).text('ব্যাকআপ চলছে...');
        status.text('').removeClass('gf-success gf-error');

        $.post(ajaxUrl, { action: 'guardify_backup_now', _ajax_nonce: nonce }, function(res) {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-database-export" style="margin-right: 4px;"></span> এখনই ব্যাকআপ নিন');
            if (res.success) {
                status.addClass('gf-success').text('✅ ' + (res.data.message || 'ব্যাকআপ সম্পন্ন!'));
                loadBackups();
            } else {
                status.addClass('gf-error').text('❌ ' + (res.data || 'ব্যাকআপ ব্যর্থ।'));
            }
        }).fail(function() {
            btn.prop('disabled', false).html('<span class="dashicons dashicons-database-export" style="margin-right: 4px;"></span> এখনই ব্যাকআপ নিন');
            status.addClass('gf-error').text('❌ সার্ভারের সাথে যোগাযোগ ব্যর্থ।');
        });
    });

    // Restore
    $(document).on('click', '#gf-restore-btn', function() {
        var backupId = $('#gf-restore-select').val();
        if (!backupId) return;

        if (!confirm('⚠ আপনি কি নিশ্চিত যে আপনি এই ব্যাকআপ থেকে ডাটাবেইজ রিস্টোর করতে চান?\n\nবর্তমান ডাটাবেইজ প্রতিস্থাপিত হবে। রিস্টোরের আগে একটি নিরাপত্তা ব্যাকআপ নেওয়া হবে।')) {
            return;
        }

        var btn = $(this);
        var status = $('#gf-restore-status');
        btn.prop('disabled', true).text('রিস্টোর চলছে...');
        status.text('').removeClass('gf-success gf-error');

        $.post(ajaxUrl, {
            action: 'guardify_backup_restore',
            _ajax_nonce: nonce,
            backup_id: backupId
        }, function(res) {
            btn.prop('disabled', false).text('রিস্টোর করুন');
            if (res.success) {
                status.addClass('gf-success').text('✅ ' + (res.data.message || 'রিস্টোর সম্পন্ন!'));
                loadBackups();
            } else {
                status.addClass('gf-error').text('❌ ' + (res.data || 'রিস্টোর ব্যর্থ।'));
            }
        }).fail(function() {
            btn.prop('disabled', false).text('রিস্টোর করুন');
            status.addClass('gf-error').text('❌ সার্ভারের সাথে যোগাযোগ ব্যর্থ।');
        });
    });

    // Save schedule
    $('#gf-save-schedule').on('click', function() {
        var btn = $(this);
        btn.prop('disabled', true);

        $.post(ajaxUrl, {
            action: 'guardify_backup_save_schedule',
            _ajax_nonce: nonce,
            guardify_backup_enabled: $('#gf-backup-enabled').is(':checked') ? 'yes' : 'no',
            guardify_backup_frequency: $('#gf-backup-frequency').val(),
            guardify_backup_time: $('#gf-backup-time').val(),
            guardify_backup_timezone: $('#gf-backup-timezone').val()
        }, function(res) {
            btn.prop('disabled', false);
            if (res.success) {
                alert('✅ ' + (res.data.message || 'শিডিউল সেভ হয়েছে।'));
            } else {
                alert('❌ ' + (res.data || 'সেভ ব্যর্থ।'));
            }
        }).fail(function() {
            btn.prop('disabled', false);
            alert('❌ সার্ভারের সাথে যোগাযোগ ব্যর্থ।');
        });
    });
});
</script>
