<?php
/**
 * Guardify Pro — Fraud Detection Admin Page
 * Blocked users, advanced block rules, export/import.
 */
defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

global $wpdb;
$tracking_table = $wpdb->prefix . 'guardify_fraud_tracking';
$blocks_table   = $wpdb->prefix . 'guardify_blocks';

// Pagination for blocked users
$per_page     = 20;
$current_page = isset($_GET['gf_page']) ? max(1, absint($_GET['gf_page'])) : 1;
$offset       = ($current_page - 1) * $per_page;
$tab          = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'blocked';

$total_blocked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tracking_table} WHERE is_blocked = 1");
$blocked_users = Guardify_Fraud_Detection::get_blocked_users($per_page, $offset);
$block_rules   = Guardify_Fraud_Detection::get_block_rules(100);
$total_tracked = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tracking_table}");
$total_pages   = max(1, (int) ceil($total_blocked / $per_page));
?>

<div class="gf-wrap">
    <div class="gf-header">
        <div class="gf-header-left">
            <div class="gf-logo">🛡️</div>
            <div>
                <h1 class="gf-page-title">ফ্রড ম্যানেজমেন্ট</h1>
                <p class="gf-page-desc">ব্লক করা ব্যবহারকারী, ব্লক রুল ও ফ্রড ট্র্যাকিং পরিচালনা।</p>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="gf-stats-grid" style="margin-bottom: 1.5rem;">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-danger">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">ব্লক করা</p>
                <p class="gf-stat-value"><?php echo esc_html($total_blocked); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">মোট ট্র্যাক</p>
                <p class="gf-stat-value"><?php echo esc_html($total_tracked); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">ব্লক রুল</p>
                <p class="gf-stat-value"><?php echo esc_html(count($block_rules)); ?></p>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <div class="gf-tabs">
        <button class="gf-tab <?php echo $tab === 'blocked' ? 'active' : ''; ?>" data-tab="blocked">ব্লক করা ব্যবহারকারী <span class="gf-count-pill" style="margin-left:6px;font-size:0.6875rem;"><?php echo esc_html($total_blocked); ?></span></button>
        <button class="gf-tab <?php echo $tab === 'rules' ? 'active' : ''; ?>" data-tab="rules">ব্লক রুল <span class="gf-count-pill gf-count-pill-muted" style="margin-left:6px;font-size:0.6875rem;"><?php echo esc_html(count($block_rules)); ?></span></button>
        <button class="gf-tab <?php echo $tab === 'export' ? 'active' : ''; ?>" data-tab="export">এক্সপোর্ট / ইম্পোর্ট</button>
    </div>

    <!-- Tab: Blocked Users -->
    <div class="gf-tab-content" id="gf-tab-blocked" style="<?php echo $tab !== 'blocked' ? 'display:none;' : ''; ?>">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ব্লক করা ফোন নম্বর</h2>
                <div style="display: flex; gap: 0.5rem;">
                    <button id="gf-bulk-unblock-btn" class="gf-btn gf-btn-secondary" style="font-size: 0.8125rem; padding: 0.375rem 0.75rem;" disabled>
                        বাল্ক আনব্লক
                    </button>
                    <button id="gf-add-block-phone-btn" class="gf-btn gf-btn-primary" style="font-size: 0.8125rem; padding: 0.375rem 0.75rem;">
                        + ফোন ব্লক করুন
                    </button>
                </div>
            </div>
            <div class="gf-card-body" style="padding: 0;">
                <?php if (empty($blocked_users)) : ?>
                <div class="gf-empty-state">
                    <div class="gf-empty-state-icon">🎉</div>
                    <p class="gf-empty-state-title">কোনো ব্লক করা ব্যবহারকারী নেই</p>
                    <p class="gf-empty-state-desc">সব গ্রাহক বর্তমানে অনুমোদিত।</p>
                </div>
                <?php else : ?>
                <div class="gf-table-wrap">
                <table class="gf-table">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="gf-select-all-blocked" /></th>
                            <th>ফোন</th>
                            <th>IP</th>
                            <th>কারণ</th>
                            <th>অর্ডার</th>
                            <th>সর্বশেষ</th>
                            <th>অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_users as $user) :
                            $order_count = !empty($user->order_ids) ? count(explode(',', $user->order_ids)) : 0;
                        ?>
                        <tr id="gf-blocked-row-<?php echo esc_attr($user->id); ?>">
                            <td><input type="checkbox" class="gf-blocked-check" value="<?php echo esc_attr($user->phone); ?>" /></td>
                            <td><strong><?php echo esc_html($user->phone); ?></strong></td>
                            <td><?php echo esc_html($user->ip_address ?: '—'); ?></td>
                            <td><span class="gf-text-muted"><?php echo esc_html($user->block_reason ?: '—'); ?></span></td>
                            <td><?php echo esc_html($order_count); ?> টি</td>
                            <td><?php echo esc_html($user->last_seen ? human_time_diff(strtotime($user->last_seen)) . ' আগে' : '—'); ?></td>
                            <td>
                                <button type="button" class="gf-btn gf-btn-secondary gf-unblock-btn" data-phone="<?php echo esc_attr($user->phone); ?>" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                    আনব্লক
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($total_pages > 1) : ?>
        <div class="gf-pagination" style="margin-top: 1rem;">
            <?php
            $base_url = admin_url('admin.php?page=guardify-fraud');
            if ($current_page > 1) :
                echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page - 1)) . '">&laquo;</a>';
            else :
                echo '<span class="gf-page-disabled">&laquo;</span>';
            endif;

            for ($i = 1; $i <= $total_pages; $i++) :
                if ($i === $current_page) :
                    echo '<span class="gf-page-current">' . esc_html($i) . '</span>';
                elseif ($i <= 2 || $i > $total_pages - 2 || abs($i - $current_page) <= 1) :
                    echo '<a href="' . esc_url($base_url . '&gf_page=' . $i) . '">' . esc_html($i) . '</a>';
                elseif ($i === 3 || $i === $total_pages - 2) :
                    echo '<span class="gf-page-dots">…</span>';
                endif;
            endfor;

            if ($current_page < $total_pages) :
                echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page + 1)) . '">&raquo;</a>';
            else :
                echo '<span class="gf-page-disabled">&raquo;</span>';
            endif;
            ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tab: Block Rules -->
    <div class="gf-tab-content" id="gf-tab-rules" style="<?php echo $tab !== 'rules' ? 'display:none;' : ''; ?>">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">অ্যাডভান্সড ব্লক রুল</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">ফোন নম্বর বা IP দিয়ে ব্লক রুল যোগ করুন। রুল সক্রিয় থাকলে চেকআউটে স্বয়ংক্রিয় ব্লক হবে।</p>
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.5rem;">
                    <select id="gf-rule-type" class="gf-input" style="width: auto; min-width: 120px;">
                        <option value="phone">ফোন</option>
                        <option value="ip">IP</option>
                    </select>
                    <input type="text" id="gf-rule-value" class="gf-input" placeholder="ভ্যালু" style="flex: 1; min-width: 200px;" />
                    <input type="text" id="gf-rule-reason" class="gf-input" placeholder="কারণ (ঐচ্ছিক)" style="flex: 1; min-width: 200px;" />
                    <button id="gf-add-rule-btn" class="gf-btn gf-btn-primary">যোগ করুন</button>
                </div>

                <?php if (empty($block_rules)) : ?>
                <div class="gf-empty-state"><p>কোনো ব্লক রুল নেই।</p></div>
                <?php else : ?>
                <div class="gf-table-wrap">
                <table class="gf-table">
                    <thead>
                        <tr>
                            <th>টাইপ</th>
                            <th>ভ্যালু</th>
                            <th>কারণ</th>
                            <th>তৈরির সময়</th>
                            <th>অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($block_rules as $rule) : ?>
                        <tr id="gf-rule-row-<?php echo esc_attr($rule->id); ?>">
                            <td>
                                <span class="gf-badge <?php echo $rule->block_type === 'phone' ? 'gf-badge-info' : 'gf-badge-warning'; ?>">
                                    <?php echo esc_html($rule->block_type === 'phone' ? 'ফোন' : 'IP'); ?>
                                </span>
                            </td>
                            <td><strong><?php echo esc_html($rule->block_value); ?></strong></td>
                            <td><span class="gf-text-muted"><?php echo esc_html($rule->reason ?: '—'); ?></span></td>
                            <td><?php echo esc_html($rule->created_at ? human_time_diff(strtotime($rule->created_at)) . ' আগে' : '—'); ?></td>
                            <td>
                                <button type="button" class="gf-btn gf-btn-danger gf-remove-rule-btn" data-id="<?php echo esc_attr($rule->id); ?>" style="font-size: 0.75rem; padding: 0.25rem 0.5rem;">
                                    মুছুন
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Tab: Export / Import -->
    <div class="gf-tab-content" id="gf-tab-export" style="<?php echo $tab !== 'export' ? 'display:none;' : ''; ?>">
        <div class="gf-card">
            <div class="gf-card-header">
                <h2 class="gf-card-title">এক্সপোর্ট</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">ব্লক করা ফোন নম্বর ও ব্লক রুল CSV ফাইলে এক্সপোর্ট করুন।</p>
                <div style="display: flex; gap: 0.75rem;">
                    <button id="gf-export-blocked-btn" class="gf-btn gf-btn-primary">ব্লক করা ব্যবহারকারী এক্সপোর্ট</button>
                    <button id="gf-export-rules-btn" class="gf-btn gf-btn-secondary">ব্লক রুল এক্সপোর্ট</button>
                </div>
            </div>
        </div>

        <div class="gf-card" style="margin-top: 1.5rem;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ইম্পোর্ট</h2>
            </div>
            <div class="gf-card-body">
                <p class="gf-text-muted" style="margin-bottom: 1rem;">CSV ফাইল থেকে ব্লক করা ফোন নম্বর ইম্পোর্ট করুন। ফরম্যাট: প্রতি লাইনে একটি ফোন নম্বর।</p>
                <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                    <input type="file" id="gf-import-file" accept=".csv,.txt" class="gf-input" style="width: auto;" />
                    <button id="gf-import-blocked-btn" class="gf-btn gf-btn-primary" disabled>ইম্পোর্ট করুন</button>
                </div>
                <div id="gf-import-msg" style="display: none; margin-top: 1rem;"></div>
            </div>
        </div>
    </div>

    <!-- Add Phone Block Modal -->
    <div id="gf-block-modal" style="display: none; position: fixed; inset: 0; z-index: 100000; background: rgba(0,0,0,0.4); display: none; align-items: center; justify-content: center;">
        <div class="gf-card" style="max-width: 420px; width: 90%; margin: auto;">
            <div class="gf-card-header">
                <h2 class="gf-card-title">ফোন নম্বর ব্লক করুন</h2>
                <button type="button" id="gf-block-modal-close" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--gf-muted-fg);">&times;</button>
            </div>
            <div class="gf-card-body">
                <div class="gf-form-group" style="margin-bottom: 1rem;">
                    <label class="gf-label">ফোন নম্বর *</label>
                    <input type="text" id="gf-block-phone" class="gf-input" placeholder="01XXXXXXXXX" />
                </div>
                <div class="gf-form-group" style="margin-bottom: 1rem;">
                    <label class="gf-label">কারণ (ঐচ্ছিক)</label>
                    <input type="text" id="gf-block-reason" class="gf-input" placeholder="ম্যানুয়াল ব্লক" />
                </div>
                <button id="gf-block-submit-btn" class="gf-btn gf-btn-danger" style="width: 100%;">ব্লক করুন</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($){
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';
    var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

    /* ── Tabs ──────────────────────────────────────────────────────────── */
    $(document).on('click', '.gf-tab', function(){
        var tab = $(this).data('tab');
        $('.gf-tab').removeClass('active');
        $(this).addClass('active');
        $('.gf-tab-content').hide();
        $('#gf-tab-' + tab).show();
    });

    /* ── Select All Checkbox ───────────────────────────────────────────── */
    $('#gf-select-all-blocked').on('change', function(){
        var checked = $(this).is(':checked');
        $('.gf-blocked-check').prop('checked', checked);
        updateBulkBtn();
    });
    $(document).on('change', '.gf-blocked-check', function(){ updateBulkBtn(); });
    function updateBulkBtn(){
        var count = $('.gf-blocked-check:checked').length;
        $('#gf-bulk-unblock-btn').prop('disabled', count === 0).text(count > 0 ? 'আনব্লক (' + count + ')' : 'বাল্ক আনব্লক');
    }

    /* ── Unblock single ────────────────────────────────────────────────── */
    $(document).on('click', '.gf-unblock-btn', function(){
        var btn = $(this), phone = btn.data('phone');
        if (!confirm(phone + ' আনব্লক করতে চান?')) return;
        btn.prop('disabled', true).text('...');
        $.post(ajaxurl, { action: 'guardify_unblock_user', _wpnonce: nonce, phone: phone }, function(r){
            if (r.success) { btn.closest('tr').fadeOut(300, function(){ $(this).remove(); }); }
            else { alert(r.data || 'ব্যর্থ'); btn.prop('disabled', false).text('আনব্লক'); }
        });
    });

    /* ── Bulk unblock ──────────────────────────────────────────────────── */
    $('#gf-bulk-unblock-btn').on('click', function(){
        var phones = [];
        $('.gf-blocked-check:checked').each(function(){ phones.push($(this).val()); });
        if (!phones.length) return;
        if (!confirm(phones.length + ' টি ফোন আনব্লক করতে চান?')) return;
        var btn = $(this);
        btn.prop('disabled', true).text('আনব্লক হচ্ছে...');
        var idx = 0;
        function nextUnblock() {
            if (idx >= phones.length) { btn.text('বাল্ক আনব্লক').prop('disabled', true); return; }
            var phone = phones[idx++];
            $.post(ajaxurl, { action: 'guardify_unblock_user', _wpnonce: nonce, phone: phone }, function(){
                $('input.gf-blocked-check[value="' + phone + '"]').closest('tr').fadeOut(300, function(){ $(this).remove(); });
                nextUnblock();
            });
        }
        nextUnblock();
    });

    /* ── Add Block Phone Modal ─────────────────────────────────────────── */
    $('#gf-add-block-phone-btn').on('click', function(){
        $('#gf-block-modal').css('display', 'flex');
    });
    $('#gf-block-modal-close, #gf-block-modal').on('click', function(e){
        if (e.target === this) $('#gf-block-modal').hide();
    });
    $('#gf-block-submit-btn').on('click', function(){
        var phone = $('#gf-block-phone').val().trim();
        var reason = $('#gf-block-reason').val().trim() || 'ম্যানুয়াল ব্লক';
        if (!phone) return;
        var btn = $(this);
        btn.prop('disabled', true).text('ব্লক হচ্ছে...');
        $.post(ajaxurl, { action: 'guardify_block_user', _wpnonce: nonce, phone: phone, reason: reason }, function(r){
            btn.prop('disabled', false).text('ব্লক করুন');
            if (r.success) {
                $('#gf-block-modal').hide();
                location.reload();
            } else {
                alert(r.data || 'ব্যর্থ');
            }
        });
    });

    /* ── Add Block Rule ────────────────────────────────────────────────── */
    $('#gf-add-rule-btn').on('click', function(){
        var type = $('#gf-rule-type').val();
        var value = $('#gf-rule-value').val().trim();
        var reason = $('#gf-rule-reason').val().trim();
        if (!value) { alert('ভ্যালু প্রয়োজন'); return; }
        var btn = $(this);
        btn.prop('disabled', true).text('যোগ হচ্ছে...');
        $.post(ajaxurl, { action: 'guardify_add_block_rule', _wpnonce: nonce, block_type: type, block_value: value, reason: reason }, function(r){
            btn.prop('disabled', false).text('যোগ করুন');
            if (r.success) { location.reload(); }
            else { alert(r.data || 'ব্যর্থ'); }
        });
    });

    /* ── Remove Block Rule ─────────────────────────────────────────────── */
    $(document).on('click', '.gf-remove-rule-btn', function(){
        var btn = $(this), id = btn.data('id');
        if (!confirm('এই রুল মুছতে চান?')) return;
        btn.prop('disabled', true).text('...');
        $.post(ajaxurl, { action: 'guardify_remove_block_rule', _wpnonce: nonce, id: id }, function(r){
            if (r.success) { btn.closest('tr').fadeOut(300, function(){ $(this).remove(); }); }
            else { alert('ব্যর্থ'); btn.prop('disabled', false).text('মুছুন'); }
        });
    });

    /* ── Export Blocked Users ──────────────────────────────────────────── */
    $('#gf-export-blocked-btn').on('click', function(){
        $(this).prop('disabled', true).text('এক্সপোর্ট হচ্ছে...');
        var self = this;
        $.post(ajaxurl, { action: 'guardify_export_blocked_users', _wpnonce: nonce }, function(r){
            $(self).prop('disabled', false).text('ব্লক করা ব্যবহারকারী এক্সপোর্ট');
            if (r.success && r.data.csv) {
                downloadCSV(r.data.csv, 'guardify-blocked-users.csv');
            } else { alert('এক্সপোর্ট ব্যর্থ'); }
        });
    });

    /* ── Export Block Rules ─────────────────────────────────────────────── */
    $('#gf-export-rules-btn').on('click', function(){
        $(this).prop('disabled', true).text('এক্সপোর্ট হচ্ছে...');
        var self = this;
        $.post(ajaxurl, { action: 'guardify_export_block_rules', _wpnonce: nonce }, function(r){
            $(self).prop('disabled', false).text('ব্লক রুল এক্সপোর্ট');
            if (r.success && r.data.csv) {
                downloadCSV(r.data.csv, 'guardify-block-rules.csv');
            } else { alert('এক্সপোর্ট ব্যর্থ'); }
        });
    });

    /* ── Import Blocked ────────────────────────────────────────────────── */
    $('#gf-import-file').on('change', function(){
        $('#gf-import-blocked-btn').prop('disabled', !this.files.length);
    });
    $('#gf-import-blocked-btn').on('click', function(){
        var file = $('#gf-import-file')[0].files[0];
        if (!file) return;
        var reader = new FileReader();
        var btn = $(this);
        var msg = $('#gf-import-msg');
        btn.prop('disabled', true).text('ইম্পোর্ট হচ্ছে...');
        reader.onload = function(e){
            var phones = e.target.result.split(/[\r\n]+/).map(function(s){ return s.trim(); }).filter(Boolean);
            if (!phones.length) { alert('ফাইলে কোনো ফোন নম্বর পাওয়া যায়নি'); btn.prop('disabled', false).text('ইম্পোর্ট করুন'); return; }
            $.post(ajaxurl, { action: 'guardify_import_blocked_users', _wpnonce: nonce, phones: JSON.stringify(phones) }, function(r){
                btn.prop('disabled', false).text('ইম্পোর্ট করুন');
                if (r.success) {
                    msg.html('<div class="gf-alert gf-alert-success">' + (r.data.message || 'ইম্পোর্ট সম্পন্ন') + '</div>').show();
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    msg.html('<div class="gf-alert gf-alert-error">' + (r.data || 'ইম্পোর্ট ব্যর্থ') + '</div>').show();
                }
            });
        };
        reader.readAsText(file);
    });

    function downloadCSV(csvString, filename){
        var BOM = '\uFEFF';
        var blob = new Blob([BOM + csvString], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url; a.download = filename;
        document.body.appendChild(a); a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
});
</script>
