<?php
/**
 * Guardify Pro — Fraud management page.
 *
 * Blocked phone numbers, manual block rules, and CSV export/import. The three
 * are separate tabs because they are separate jobs: reviewing who got blocked,
 * adding a block by hand, and moving the list between sites.
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

$valid_tabs = ['blocked', 'rules', 'export'];
if (!in_array($tab, $valid_tabs, true)) {
    $tab = 'blocked';
}
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title">ফ্রড ম্যানেজমেন্ট</h1>
                <p class="gf-page-desc">ব্লক করা গ্রাহক, ম্যানুয়াল ব্লক রুল ও ফ্রড ট্র্যাকিং।</p>
            </div>
        </div>
    </div>

    <div class="gf-stats-grid">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-danger" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">ব্লক করা নম্বর</p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($total_blocked)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">মোট ট্র্যাক করা</p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($total_tracked)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label">ম্যানুয়াল রুল</p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n(count($block_rules))); ?></p>
            </div>
        </div>
    </div>

    <div data-gf-tabs>

        <div class="gf-tabs" role="tablist">
            <button type="button" class="gf-tab <?php echo $tab === 'blocked' ? 'active' : ''; ?>" data-tab="blocked" role="tab" aria-selected="<?php echo $tab === 'blocked' ? 'true' : 'false'; ?>">
                ব্লক করা গ্রাহক
                <span class="gf-count-pill"><?php echo esc_html(number_format_i18n($total_blocked)); ?></span>
            </button>
            <button type="button" class="gf-tab <?php echo $tab === 'rules' ? 'active' : ''; ?>" data-tab="rules" role="tab" aria-selected="<?php echo $tab === 'rules' ? 'true' : 'false'; ?>">
                ব্লক রুল
                <span class="gf-count-pill gf-count-pill-muted"><?php echo esc_html(number_format_i18n(count($block_rules))); ?></span>
            </button>
            <button type="button" class="gf-tab <?php echo $tab === 'export' ? 'active' : ''; ?>" data-tab="export" role="tab" aria-selected="<?php echo $tab === 'export' ? 'true' : 'false'; ?>">
                এক্সপোর্ট / ইম্পোর্ট
            </button>
        </div>

        <!-- ── Tab: Blocked users ───────────────────────────────────────── -->
        <div class="gf-tab-content <?php echo $tab === 'blocked' ? 'active' : ''; ?>" id="gf-tab-blocked" <?php echo $tab !== 'blocked' ? 'hidden' : ''; ?>>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ব্লক করা ফোন নম্বর</h2>
                        <p class="gf-card-desc">এই নম্বরগুলো চেকআউটে অর্ডার করতে পারবে না। ভুল করে ব্লক হয়ে গেলে আনব্লক করে দিন।</p>
                    </div>
                    <div class="gf-card-header-actions">
                        <button type="button" id="gf-bulk-unblock-btn" class="gf-btn gf-btn-secondary gf-btn-sm" disabled>বাল্ক আনব্লক</button>
                        <button type="button" id="gf-add-block-phone-btn" class="gf-btn gf-btn-primary gf-btn-sm" data-gf-open="#gf-block-modal">ফোন ব্লক করুন</button>
                    </div>
                </div>
                <div class="gf-card-body gf-flush">
                    <?php if (empty($blocked_users)) : ?>
                    <div class="gf-empty-state">
                        <div class="gf-empty-state-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </div>
                        <p class="gf-empty-state-title">কোনো ব্লক করা গ্রাহক নেই</p>
                        <p class="gf-empty-state-desc">এই মুহূর্তে সব গ্রাহক অর্ডার করতে পারছেন। অটো-ব্লকের নিয়ম সেটিংস → সুরক্ষা নিয়ম থেকে ঠিক করুন।</p>
                    </div>
                    <?php else : ?>
                    <div class="gf-table-wrap">
                        <table class="gf-table gf-table-stack">
                            <thead>
                                <tr>
                                    <th class="gf-col-check" data-label="">
                                        <input type="checkbox" id="gf-select-all-blocked" class="gf-check" aria-label="সব সিলেক্ট করুন" />
                                    </th>
                                    <th>ফোন</th>
                                    <th>IP</th>
                                    <th>কারণ</th>
                                    <th class="gf-table-num">অর্ডার</th>
                                    <th>সর্বশেষ</th>
                                    <th class="gf-col-action" data-label="">অ্যাকশন</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($blocked_users as $user) :
                                    $order_count = !empty($user->order_ids) ? count(explode(',', $user->order_ids)) : 0;
                                ?>
                                <tr id="gf-blocked-row-<?php echo esc_attr($user->id); ?>">
                                    <td>
                                        <input type="checkbox" class="gf-check gf-blocked-check" value="<?php echo esc_attr($user->phone); ?>"
                                            aria-label="<?php echo esc_attr($user->phone); ?>" />
                                    </td>
                                    <td><strong class="gf-mono"><?php echo esc_html($user->phone); ?></strong></td>
                                    <td class="gf-mono gf-text-muted"><?php echo esc_html($user->ip_address ?: '—'); ?></td>
                                    <td class="gf-text-muted"><?php echo esc_html($user->block_reason ?: '—'); ?></td>
                                    <td class="gf-table-num"><?php echo esc_html(number_format_i18n($order_count)); ?></td>
                                    <td class="gf-table-nowrap gf-text-muted">
                                        <?php echo esc_html($user->last_seen ? human_time_diff(strtotime($user->last_seen)) . ' আগে' : '—'); ?>
                                    </td>
                                    <td class="gf-col-action">
                                        <button type="button" class="gf-btn gf-btn-secondary gf-btn-sm gf-unblock-btn" data-phone="<?php echo esc_attr($user->phone); ?>">আনব্লক</button>
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
            <nav class="gf-pagination" aria-label="পেজ নেভিগেশন">
                <?php
                $base_url = admin_url('admin.php?page=guardify-fraud');
                if ($current_page > 1) :
                    echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page - 1)) . '" aria-label="আগের পেজ">&laquo;</a>';
                else :
                    echo '<span class="gf-page-disabled">&laquo;</span>';
                endif;

                for ($i = 1; $i <= $total_pages; $i++) :
                    if ($i === $current_page) :
                        echo '<span class="gf-page-current" aria-current="page">' . esc_html(number_format_i18n($i)) . '</span>';
                    elseif ($i <= 2 || $i > $total_pages - 2 || abs($i - $current_page) <= 1) :
                        echo '<a href="' . esc_url($base_url . '&gf_page=' . $i) . '">' . esc_html(number_format_i18n($i)) . '</a>';
                    elseif ($i === 3 || $i === $total_pages - 2) :
                        echo '<span class="gf-page-dots">…</span>';
                    endif;
                endfor;

                if ($current_page < $total_pages) :
                    echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page + 1)) . '" aria-label="পরের পেজ">&raquo;</a>';
                else :
                    echo '<span class="gf-page-disabled">&raquo;</span>';
                endif;
                ?>
            </nav>
            <?php endif; ?>
        </div>

        <!-- ── Tab: Block rules ─────────────────────────────────────────── -->
        <div class="gf-tab-content <?php echo $tab === 'rules' ? 'active' : ''; ?>" id="gf-tab-rules" <?php echo $tab !== 'rules' ? 'hidden' : ''; ?>>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">নতুন ব্লক রুল</h2>
                        <p class="gf-card-desc">একটি ফোন নম্বর বা IP ঠিকানা দিয়ে রুল যোগ করুন। রুল সক্রিয় থাকা অবস্থায় ওই নম্বর বা IP থেকে চেকআউট আটকে যাবে।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row" style="align-items: flex-end;">
                        <div class="gf-field" style="width: 130px;">
                            <label class="gf-label" for="gf-rule-type">টাইপ</label>
                            <select id="gf-rule-type" class="gf-select">
                                <option value="phone">ফোন</option>
                                <option value="ip">IP</option>
                            </select>
                        </div>
                        <div class="gf-field gf-grow" style="min-width: 180px;">
                            <label class="gf-label" for="gf-rule-value">ভ্যালু</label>
                            <input type="text" id="gf-rule-value" class="gf-input gf-input-mono" placeholder="01XXXXXXXXX" />
                        </div>
                        <div class="gf-field gf-grow" style="min-width: 180px;">
                            <label class="gf-label" for="gf-rule-reason">কারণ <span class="gf-text-muted">(ঐচ্ছিক)</span></label>
                            <input type="text" id="gf-rule-reason" class="gf-input" placeholder="কেন ব্লক করছেন" />
                        </div>
                        <button type="button" id="gf-add-rule-btn" class="gf-btn gf-btn-primary">যোগ করুন</button>
                    </div>
                    <p class="gf-help gf-mt-2">কারণ লিখে রাখলে কয়েক মাস পরেও বোঝা যাবে কেন ব্লক করা হয়েছিল।</p>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <h2 class="gf-card-title">সক্রিয় রুল</h2>
                </div>
                <div class="gf-card-body gf-flush">
                    <?php if (empty($block_rules)) : ?>
                    <div class="gf-empty-state">
                        <div class="gf-empty-state-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                        </div>
                        <p class="gf-empty-state-title">কোনো ম্যানুয়াল ব্লক রুল নেই</p>
                        <p class="gf-empty-state-desc">উপরের ফর্ম থেকে প্রথম রুল যোগ করুন।</p>
                    </div>
                    <?php else : ?>
                    <div class="gf-table-wrap">
                        <table class="gf-table gf-table-stack">
                            <thead>
                                <tr>
                                    <th>টাইপ</th>
                                    <th>ভ্যালু</th>
                                    <th>কারণ</th>
                                    <th>তৈরির সময়</th>
                                    <th class="gf-col-action" data-label="">অ্যাকশন</th>
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
                                    <td><strong class="gf-mono"><?php echo esc_html($rule->block_value); ?></strong></td>
                                    <td class="gf-text-muted"><?php echo esc_html($rule->reason ?: '—'); ?></td>
                                    <td class="gf-table-nowrap gf-text-muted">
                                        <?php echo esc_html($rule->created_at ? human_time_diff(strtotime($rule->created_at)) . ' আগে' : '—'); ?>
                                    </td>
                                    <td class="gf-col-action">
                                        <button type="button" class="gf-btn gf-btn-ghost gf-btn-sm gf-text-danger gf-remove-rule-btn" data-id="<?php echo esc_attr($rule->id); ?>">মুছুন</button>
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

        <!-- ── Tab: Export / import ─────────────────────────────────────── -->
        <div class="gf-tab-content <?php echo $tab === 'export' ? 'active' : ''; ?>" id="gf-tab-export" <?php echo $tab !== 'export' ? 'hidden' : ''; ?>>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">এক্সপোর্ট</h2>
                        <p class="gf-card-desc">CSV ফাইল হিসেবে ডাউনলোড হবে। একাধিক দোকান চালালে এক সাইটের ব্লক লিস্ট অন্য সাইটে নিতে এটি ব্যবহার করুন।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row">
                        <button type="button" id="gf-export-blocked-btn" class="gf-btn gf-btn-secondary">ব্লক করা গ্রাহক এক্সপোর্ট</button>
                        <button type="button" id="gf-export-rules-btn" class="gf-btn gf-btn-secondary">ব্লক রুল এক্সপোর্ট</button>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title">ইম্পোর্ট</h2>
                        <p class="gf-card-desc">ফাইলের প্রতি লাইনে একটি ফোন নম্বর থাকতে হবে। ইম্পোর্ট করা নম্বরগুলো সরাসরি ব্লক হয়ে যাবে।</p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row" style="align-items: flex-end;">
                        <div class="gf-field gf-field-mid gf-grow">
                            <label class="gf-label" for="gf-import-file">CSV বা TXT ফাইল</label>
                            <input type="file" id="gf-import-file" accept=".csv,.txt" class="gf-input" />
                        </div>
                        <button type="button" id="gf-import-blocked-btn" class="gf-btn gf-btn-primary" disabled>ইম্পোর্ট করুন</button>
                    </div>
                    <div id="gf-import-msg" class="gf-mt-2" style="display:none;" role="status"></div>
                </div>
            </div>
        </div>

    </div>

    <div id="gf-block-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-block-modal-title" style="display:none;">
        <div class="gf-modal gf-modal-sm">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-block-modal-title">ফোন নম্বর ব্লক করুন</h2>
                <button type="button" class="gf-modal-close" id="gf-block-modal-close" data-gf-close aria-label="বন্ধ করুন">&times;</button>
            </div>
            <div class="gf-modal-body">
                <div class="gf-field">
                    <label class="gf-label" for="gf-block-phone">ফোন নম্বর <span class="gf-required">*</span></label>
                    <input type="text" id="gf-block-phone" class="gf-input gf-input-mono" placeholder="01XXXXXXXXX" inputmode="tel" />
                    <span class="gf-help">১১ সংখ্যার বাংলাদেশি নম্বর, ০ দিয়ে শুরু।</span>
                </div>
                <div class="gf-field">
                    <label class="gf-label" for="gf-block-reason">কারণ <span class="gf-text-muted">(ঐচ্ছিক)</span></label>
                    <input type="text" id="gf-block-reason" class="gf-input" placeholder="ম্যানুয়াল ব্লক" />
                </div>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary" data-gf-close>বাতিল</button>
                <button type="button" id="gf-block-submit-btn" class="gf-btn gf-btn-danger">ব্লক করুন</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function ($) {
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';
    var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var GF = window.Guardify;

    /* ── Selection ────────────────────────────────────────────────────── */

    function updateBulkBtn() {
        var count = $('.gf-blocked-check:checked').length;
        $('#gf-bulk-unblock-btn')
            .prop('disabled', count === 0)
            .text(count > 0 ? 'আনব্লক (' + count + ')' : 'বাল্ক আনব্লক');
    }

    $('#gf-select-all-blocked').on('change', function () {
        $('.gf-blocked-check').prop('checked', this.checked);
        updateBulkBtn();
    });
    $(document).on('change', '.gf-blocked-check', updateBulkBtn);

    /* ── Unblock ──────────────────────────────────────────────────────── */

    $(document).on('click', '.gf-unblock-btn', function () {
        var $btn = $(this);
        var phone = $btn.data('phone');
        if (!confirm(phone + ' আনব্লক করতে চান? এই নম্বর আবার অর্ডার করতে পারবে।')) {
            return;
        }
        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: 'guardify_unblock_user', _wpnonce: nonce, phone: phone }, function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(180, function () { $(this).remove(); updateBulkBtn(); });
                GF.toast(phone + ' আনব্লক হয়েছে।', { type: 'success' });
            } else {
                GF.setLoading($btn, false);
                GF.toast(r.data || 'আনব্লক করা যায়নি।', { type: 'error' });
            }
        });
    });

    $('#gf-bulk-unblock-btn').on('click', function () {
        var phones = $('.gf-blocked-check:checked').map(function () { return $(this).val(); }).get();
        if (!phones.length) { return; }
        if (!confirm(phones.length + ' টি ফোন আনব্লক করতে চান?')) { return; }

        var $btn = $(this);
        GF.setLoading($btn, true);

        // Serialised rather than fired in parallel: each unblock is a write and
        // a shared host will start refusing a burst of twenty.
        var idx = 0;
        function nextUnblock() {
            if (idx >= phones.length) {
                GF.setLoading($btn, false);
                $btn.prop('disabled', true).text('বাল্ক আনব্লক');
                GF.toast(phones.length + ' টি নম্বর আনব্লক হয়েছে।', { type: 'success' });
                return;
            }
            var phone = phones[idx++];
            $.post(ajaxurl, { action: 'guardify_unblock_user', _wpnonce: nonce, phone: phone })
                .always(function () {
                    $('input.gf-blocked-check[value="' + phone + '"]').closest('tr').fadeOut(180, function () { $(this).remove(); });
                    nextUnblock();
                });
        }
        nextUnblock();
    });

    /* ── Add a phone block ────────────────────────────────────────────── */

    $('#gf-block-submit-btn').on('click', function () {
        var $btn = $(this);
        var phone = $('#gf-block-phone').val().trim();
        var reason = $('#gf-block-reason').val().trim() || 'ম্যানুয়াল ব্লক';

        if (!phone) {
            $('#gf-block-phone').addClass('is-invalid').trigger('focus');
            return;
        }
        $('#gf-block-phone').removeClass('is-invalid');

        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: 'guardify_block_user', _wpnonce: nonce, phone: phone, reason: reason }, function (r) {
            GF.setLoading($btn, false);
            if (r.success) {
                GF.closeModal('#gf-block-modal');
                location.reload();
            } else {
                GF.toast(r.data || 'ব্লক করা যায়নি।', { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
        });
    });

    /* ── Block rules ──────────────────────────────────────────────────── */

    $('#gf-add-rule-btn').on('click', function () {
        var $btn = $(this);
        var type = $('#gf-rule-type').val();
        var value = $('#gf-rule-value').val().trim();
        var reason = $('#gf-rule-reason').val().trim();

        if (!value) {
            $('#gf-rule-value').addClass('is-invalid').trigger('focus');
            return;
        }
        $('#gf-rule-value').removeClass('is-invalid');

        GF.setLoading($btn, true);
        $.post(ajaxurl, {
            action: 'guardify_add_block_rule',
            _wpnonce: nonce,
            block_type: type,
            block_value: value,
            reason: reason
        }, function (r) {
            GF.setLoading($btn, false);
            if (r.success) {
                location.reload();
            } else {
                GF.toast(r.data || 'রুল যোগ করা যায়নি।', { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
        });
    });

    $(document).on('click', '.gf-remove-rule-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        if (!confirm('এই রুল মুছতে চান?')) { return; }

        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: 'guardify_remove_block_rule', _wpnonce: nonce, id: id }, function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(180, function () { $(this).remove(); });
            } else {
                GF.setLoading($btn, false);
                GF.toast('রুল মোছা যায়নি।', { type: 'error' });
            }
        });
    });

    /* ── Export ───────────────────────────────────────────────────────── */

    function exportCsv($btn, action, filename) {
        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: action, _wpnonce: nonce }, function (r) {
            GF.setLoading($btn, false);
            if (r.success && r.data.csv) {
                downloadCSV(r.data.csv, filename);
            } else {
                GF.toast('এক্সপোর্ট ব্যর্থ হয়েছে।', { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
        });
    }

    $('#gf-export-blocked-btn').on('click', function () {
        exportCsv($(this), 'guardify_export_blocked_users', 'guardify-blocked-users.csv');
    });

    $('#gf-export-rules-btn').on('click', function () {
        exportCsv($(this), 'guardify_export_block_rules', 'guardify-block-rules.csv');
    });

    /* ── Import ───────────────────────────────────────────────────────── */

    $('#gf-import-file').on('change', function () {
        $('#gf-import-blocked-btn').prop('disabled', !this.files.length);
    });

    $('#gf-import-blocked-btn').on('click', function () {
        var file = $('#gf-import-file')[0].files[0];
        if (!file) { return; }

        var $btn = $(this);
        var $msg = $('#gf-import-msg');
        var reader = new FileReader();

        GF.setLoading($btn, true);
        reader.onload = function (e) {
            var phones = e.target.result.split(/[\r\n]+/).map(function (s) { return s.trim(); }).filter(Boolean);
            if (!phones.length) {
                GF.setLoading($btn, false);
                GF.toast('ফাইলে কোনো ফোন নম্বর পাওয়া যায়নি।', { type: 'error' });
                return;
            }

            $.post(ajaxurl, {
                action: 'guardify_import_blocked_users',
                _wpnonce: nonce,
                phones: JSON.stringify(phones)
            }, function (r) {
                GF.setLoading($btn, false);
                var ok = !!r.success;
                $msg.attr('class', 'gf-alert gf-mt-2 ' + (ok ? 'gf-alert-success' : 'gf-alert-error'))
                    .text(ok ? (r.data.message || 'ইম্পোর্ট সম্পন্ন') : (r.data || 'ইম্পোর্ট ব্যর্থ'))
                    .show();
                if (ok) {
                    setTimeout(function () { location.reload(); }, 1500);
                }
            }).fail(function () {
                GF.setLoading($btn, false);
                GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
            });
        };
        reader.readAsText(file);
    });

    /* A BOM is what makes Excel open a UTF-8 CSV as Bengali instead of as
       mojibake, and Excel is what every merchant will open this in. */
    function downloadCSV(csvString, filename) {
        var blob = new Blob(['\uFEFF' + csvString], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }
});
</script>
