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
                <h1 class="gf-page-title"><?php esc_html_e('ফ্রড ম্যানেজমেন্ট', 'guardify-pro'); ?></h1>
                <p class="gf-page-desc"><?php esc_html_e('ব্লক করা গ্রাহক, ম্যানুয়াল ব্লক রুল ও ফ্রড ট্র্যাকিং।', 'guardify-pro'); ?></p>
            </div>
        </div>
    </div>

    <div class="gf-stats-grid">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-danger" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('ব্লক করা নম্বর', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($total_blocked)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('মোট ট্র্যাক করা', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($total_tracked)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('ম্যানুয়াল রুল', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n(count($block_rules))); ?></p>
            </div>
        </div>
    </div>

    <div data-gf-tabs>

        <div class="gf-tabs" role="tablist">
            <button type="button" class="gf-tab <?php echo $tab === 'blocked' ? 'active' : ''; ?>" data-tab="blocked" role="tab" aria-selected="<?php echo $tab === 'blocked' ? 'true' : 'false'; ?>">
                <?php esc_html_e('ব্লক করা গ্রাহক', 'guardify-pro'); ?>
                <span class="gf-count-pill"><?php echo esc_html(number_format_i18n($total_blocked)); ?></span>
            </button>
            <button type="button" class="gf-tab <?php echo $tab === 'rules' ? 'active' : ''; ?>" data-tab="rules" role="tab" aria-selected="<?php echo $tab === 'rules' ? 'true' : 'false'; ?>">
                <?php esc_html_e('ব্লক রুল', 'guardify-pro'); ?>
                <span class="gf-count-pill gf-count-pill-muted"><?php echo esc_html(number_format_i18n(count($block_rules))); ?></span>
            </button>
            <button type="button" class="gf-tab <?php echo $tab === 'export' ? 'active' : ''; ?>" data-tab="export" role="tab" aria-selected="<?php echo $tab === 'export' ? 'true' : 'false'; ?>">
                <?php esc_html_e('এক্সপোর্ট / ইম্পোর্ট', 'guardify-pro'); ?>
            </button>
        </div>

        <!-- ── Tab: Blocked users ───────────────────────────────────────── -->
        <div class="gf-tab-content <?php echo $tab === 'blocked' ? 'active' : ''; ?>" id="gf-tab-blocked" <?php echo $tab !== 'blocked' ? 'hidden' : ''; ?>>
            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title"><?php esc_html_e('ব্লক করা ফোন নম্বর', 'guardify-pro'); ?></h2>
                        <p class="gf-card-desc"><?php esc_html_e('এই নম্বরগুলো চেকআউটে অর্ডার করতে পারবে না। ভুল করে ব্লক হয়ে গেলে আনব্লক করে দিন।', 'guardify-pro'); ?></p>
                    </div>
                    <div class="gf-card-header-actions">
                        <button type="button" id="gf-bulk-unblock-btn" class="gf-btn gf-btn-secondary gf-btn-sm" disabled><?php esc_html_e('বাল্ক আনব্লক', 'guardify-pro'); ?></button>
                        <button type="button" id="gf-add-block-phone-btn" class="gf-btn gf-btn-primary gf-btn-sm" data-gf-open="#gf-block-modal"><?php esc_html_e('ফোন ব্লক করুন', 'guardify-pro'); ?></button>
                    </div>
                </div>
                <div class="gf-card-body gf-flush">
                    <?php if (empty($blocked_users)) : ?>
                    <div class="gf-empty-state">
                        <div class="gf-empty-state-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                        </div>
                        <p class="gf-empty-state-title"><?php esc_html_e('কোনো ব্লক করা গ্রাহক নেই', 'guardify-pro'); ?></p>
                        <p class="gf-empty-state-desc"><?php esc_html_e('এই মুহূর্তে সব গ্রাহক অর্ডার করতে পারছেন। অটো-ব্লকের নিয়ম সেটিংস → সুরক্ষা নিয়ম থেকে ঠিক করুন।', 'guardify-pro'); ?></p>
                    </div>
                    <?php else : ?>
                    <div class="gf-table-wrap">
                        <table class="gf-table gf-table-stack">
                            <thead>
                                <tr>
                                    <th class="gf-col-check" data-label="">
                                        <input type="checkbox" id="gf-select-all-blocked" class="gf-check" aria-label="<?php echo esc_attr__('সব সিলেক্ট করুন', 'guardify-pro'); ?>" />
                                    </th>
                                    <th><?php esc_html_e('ফোন', 'guardify-pro'); ?></th>
                                    <th>IP</th>
                                    <th><?php esc_html_e('কারণ', 'guardify-pro'); ?></th>
                                    <th class="gf-table-num"><?php esc_html_e('অর্ডার', 'guardify-pro'); ?></th>
                                    <th><?php esc_html_e('সর্বশেষ', 'guardify-pro'); ?></th>
                                    <th class="gf-col-action" data-label=""><?php esc_html_e('অ্যাকশন', 'guardify-pro'); ?></th>
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
                                        <?php echo esc_html($user->last_seen ? human_time_diff(strtotime($user->last_seen)) . __(' আগে', 'guardify-pro') : '—'); ?>
                                    </td>
                                    <td class="gf-col-action">
                                        <button type="button" class="gf-btn gf-btn-secondary gf-btn-sm gf-unblock-btn" data-phone="<?php echo esc_attr($user->phone); ?>"><?php esc_html_e('আনব্লক', 'guardify-pro'); ?></button>
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
            <nav class="gf-pagination" aria-label="<?php echo esc_attr__('পেজ নেভিগেশন', 'guardify-pro'); ?>">
                <?php
                $base_url = admin_url('admin.php?page=guardify-fraud');
                if ($current_page > 1) :
                    echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page - 1)) . __('" aria-label="আগের পেজ">&laquo;</a>', 'guardify-pro');
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
                    echo '<a href="' . esc_url($base_url . '&gf_page=' . ($current_page + 1)) . __('" aria-label="পরের পেজ">&raquo;</a>', 'guardify-pro');
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
                        <h2 class="gf-card-title"><?php esc_html_e('নতুন ব্লক রুল', 'guardify-pro'); ?></h2>
                        <p class="gf-card-desc"><?php esc_html_e('একটি ফোন নম্বর বা IP ঠিকানা দিয়ে রুল যোগ করুন। রুল সক্রিয় থাকা অবস্থায় ওই নম্বর বা IP থেকে চেকআউট আটকে যাবে।', 'guardify-pro'); ?></p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row" style="align-items: flex-end;">
                        <div class="gf-field" style="width: 130px;">
                            <label class="gf-label" for="gf-rule-type"><?php esc_html_e('টাইপ', 'guardify-pro'); ?></label>
                            <select id="gf-rule-type" class="gf-select">
                                <option value="phone"><?php esc_html_e('ফোন', 'guardify-pro'); ?></option>
                                <option value="ip">IP</option>
                            </select>
                        </div>
                        <div class="gf-field gf-grow" style="min-width: 180px;">
                            <label class="gf-label" for="gf-rule-value"><?php esc_html_e('ভ্যালু', 'guardify-pro'); ?></label>
                            <input type="text" id="gf-rule-value" class="gf-input gf-input-mono" placeholder="01XXXXXXXXX" />
                        </div>
                        <div class="gf-field gf-grow" style="min-width: 180px;">
                            <label class="gf-label" for="gf-rule-reason"><?php esc_html_e('কারণ', 'guardify-pro'); ?> <span class="gf-text-muted"><?php esc_html_e('(ঐচ্ছিক)', 'guardify-pro'); ?></span></label>
                            <input type="text" id="gf-rule-reason" class="gf-input" placeholder="<?php echo esc_attr__('কেন ব্লক করছেন', 'guardify-pro'); ?>" />
                        </div>
                        <button type="button" id="gf-add-rule-btn" class="gf-btn gf-btn-primary"><?php esc_html_e('যোগ করুন', 'guardify-pro'); ?></button>
                    </div>
                    <p class="gf-help gf-mt-2"><?php esc_html_e('কারণ লিখে রাখলে কয়েক মাস পরেও বোঝা যাবে কেন ব্লক করা হয়েছিল।', 'guardify-pro'); ?></p>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <h2 class="gf-card-title"><?php esc_html_e('সক্রিয় রুল', 'guardify-pro'); ?></h2>
                </div>
                <div class="gf-card-body gf-flush">
                    <?php if (empty($block_rules)) : ?>
                    <div class="gf-empty-state">
                        <div class="gf-empty-state-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h10"/></svg>
                        </div>
                        <p class="gf-empty-state-title"><?php esc_html_e('কোনো ম্যানুয়াল ব্লক রুল নেই', 'guardify-pro'); ?></p>
                        <p class="gf-empty-state-desc"><?php esc_html_e('উপরের ফর্ম থেকে প্রথম রুল যোগ করুন।', 'guardify-pro'); ?></p>
                    </div>
                    <?php else : ?>
                    <div class="gf-table-wrap">
                        <table class="gf-table gf-table-stack">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('টাইপ', 'guardify-pro'); ?></th>
                                    <th><?php esc_html_e('ভ্যালু', 'guardify-pro'); ?></th>
                                    <th><?php esc_html_e('কারণ', 'guardify-pro'); ?></th>
                                    <th><?php esc_html_e('তৈরির সময়', 'guardify-pro'); ?></th>
                                    <th class="gf-col-action" data-label=""><?php esc_html_e('অ্যাকশন', 'guardify-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($block_rules as $rule) : ?>
                                <tr id="gf-rule-row-<?php echo esc_attr($rule->id); ?>">
                                    <td>
                                        <span class="gf-badge <?php echo $rule->block_type === 'phone' ? 'gf-badge-info' : 'gf-badge-warning'; ?>">
                                            <?php echo esc_html($rule->block_type === 'phone' ? __('ফোন', 'guardify-pro') : 'IP'); ?>
                                        </span>
                                    </td>
                                    <td><strong class="gf-mono"><?php echo esc_html($rule->block_value); ?></strong></td>
                                    <td class="gf-text-muted"><?php echo esc_html($rule->reason ?: '—'); ?></td>
                                    <td class="gf-table-nowrap gf-text-muted">
                                        <?php echo esc_html($rule->created_at ? human_time_diff(strtotime($rule->created_at)) . __(' আগে', 'guardify-pro') : '—'); ?>
                                    </td>
                                    <td class="gf-col-action">
                                        <button type="button" class="gf-btn gf-btn-ghost gf-btn-sm gf-text-danger gf-remove-rule-btn" data-id="<?php echo esc_attr($rule->id); ?>"><?php esc_html_e('মুছুন', 'guardify-pro'); ?></button>
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
                        <h2 class="gf-card-title"><?php esc_html_e('এক্সপোর্ট', 'guardify-pro'); ?></h2>
                        <p class="gf-card-desc"><?php esc_html_e('CSV ফাইল হিসেবে ডাউনলোড হবে। একাধিক দোকান চালালে এক সাইটের ব্লক লিস্ট অন্য সাইটে নিতে এটি ব্যবহার করুন।', 'guardify-pro'); ?></p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row">
                        <button type="button" id="gf-export-blocked-btn" class="gf-btn gf-btn-secondary"><?php esc_html_e('ব্লক করা গ্রাহক এক্সপোর্ট', 'guardify-pro'); ?></button>
                        <button type="button" id="gf-export-rules-btn" class="gf-btn gf-btn-secondary"><?php esc_html_e('ব্লক রুল এক্সপোর্ট', 'guardify-pro'); ?></button>
                    </div>
                </div>
            </div>

            <div class="gf-card">
                <div class="gf-card-header">
                    <div class="gf-card-heading">
                        <h2 class="gf-card-title"><?php esc_html_e('ইম্পোর্ট', 'guardify-pro'); ?></h2>
                        <p class="gf-card-desc"><?php esc_html_e('ফাইলের প্রতি লাইনে একটি ফোন নম্বর থাকতে হবে। ইম্পোর্ট করা নম্বরগুলো সরাসরি ব্লক হয়ে যাবে।', 'guardify-pro'); ?></p>
                    </div>
                </div>
                <div class="gf-card-body">
                    <div class="gf-row" style="align-items: flex-end;">
                        <div class="gf-field gf-field-mid gf-grow">
                            <label class="gf-label" for="gf-import-file"><?php esc_html_e('CSV বা TXT ফাইল', 'guardify-pro'); ?></label>
                            <input type="file" id="gf-import-file" accept=".csv,.txt" class="gf-input" />
                        </div>
                        <button type="button" id="gf-import-blocked-btn" class="gf-btn gf-btn-primary" disabled><?php esc_html_e('ইম্পোর্ট করুন', 'guardify-pro'); ?></button>
                    </div>
                    <div id="gf-import-msg" class="gf-mt-2" style="display:none;" role="status"></div>
                </div>
            </div>
        </div>

    </div>

    <div id="gf-block-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-block-modal-title" style="display:none;">
        <div class="gf-modal gf-modal-sm">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-block-modal-title"><?php esc_html_e('ফোন নম্বর ব্লক করুন', 'guardify-pro'); ?></h2>
                <button type="button" class="gf-modal-close" id="gf-block-modal-close" data-gf-close aria-label="<?php echo esc_attr__('বন্ধ করুন', 'guardify-pro'); ?>">&times;</button>
            </div>
            <div class="gf-modal-body">
                <div class="gf-field">
                    <label class="gf-label" for="gf-block-phone"><?php esc_html_e('ফোন নম্বর', 'guardify-pro'); ?> <span class="gf-required">*</span></label>
                    <input type="text" id="gf-block-phone" class="gf-input gf-input-mono" placeholder="01XXXXXXXXX" inputmode="tel" />
                    <span class="gf-help"><?php esc_html_e('১১ সংখ্যার বাংলাদেশি নম্বর, ০ দিয়ে শুরু।', 'guardify-pro'); ?></span>
                </div>
                <div class="gf-field">
                    <label class="gf-label" for="gf-block-reason"><?php esc_html_e('কারণ', 'guardify-pro'); ?> <span class="gf-text-muted"><?php esc_html_e('(ঐচ্ছিক)', 'guardify-pro'); ?></span></label>
                    <input type="text" id="gf-block-reason" class="gf-input" placeholder="<?php echo esc_attr__('ম্যানুয়াল ব্লক', 'guardify-pro'); ?>" />
                </div>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary" data-gf-close><?php esc_html_e('বাতিল', 'guardify-pro'); ?></button>
                <button type="button" id="gf-block-submit-btn" class="gf-btn gf-btn-danger"><?php esc_html_e('ব্লক করুন', 'guardify-pro'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
/* Every Bengali string on this page, translated by PHP and handed to the
   script as one object. Inline JavaScript cannot call __() itself, and opening
   a PHP tag inside a JS string literal produces something neither PHP nor the
   browser can parse — so the strings are lifted out rather than wrapped where
   they sit. wp_json_encode does the escaping, once. */
var GF_I18N = <?php echo wp_json_encode([
        's024a3018' => __('রুল মোছা যায়নি।', 'guardify-pro'),
        's20f5c379' => __('রুল যোগ করা যায়নি।', 'guardify-pro'),
        's311715cf' => __('ব্লক করা যায়নি।', 'guardify-pro'),
        's33da74fe' => __('এক্সপোর্ট ব্যর্থ হয়েছে।', 'guardify-pro'),
        's85a15116' => __('ম্যানুয়াল ব্লক', 'guardify-pro'),
        'saa68907c' => __('এই রুল মুছতে চান?', 'guardify-pro'),
        'saa9b9408' => __('আনব্লক করা যায়নি।', 'guardify-pro'),
        'sae62bbf1' => __(' টি নম্বর আনব্লক হয়েছে।', 'guardify-pro'),
        'saf20aca8' => __(' আনব্লক হয়েছে।', 'guardify-pro'),
        'sb05fd735' => __(' টি ফোন আনব্লক করতে চান?', 'guardify-pro'),
        'sb06b1f19' => __('ইম্পোর্ট সম্পন্ন', 'guardify-pro'),
        'sc0a7e89e' => __(' আনব্লক করতে চান? এই নম্বর আবার অর্ডার করতে পারবে।', 'guardify-pro'),
        'sce6be67d' => __('সার্ভারে সংযোগ করা যায়নি।', 'guardify-pro'),
        'scf693656' => __('ফাইলে কোনো ফোন নম্বর পাওয়া যায়নি।', 'guardify-pro'),
        'se697ccc7' => __('আনব্লক (', 'guardify-pro'),
        'se808382d' => __('বাল্ক আনব্লক', 'guardify-pro'),
        'see36b123' => __('ইম্পোর্ট ব্যর্থ', 'guardify-pro'),
    ]); ?>;

jQuery(function ($) {
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';
    var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
    var GF = window.Guardify;

    /* ── Selection ────────────────────────────────────────────────────── */

    function updateBulkBtn() {
        var count = $('.gf-blocked-check:checked').length;
        $('#gf-bulk-unblock-btn')
            .prop('disabled', count === 0)
            .text(count > 0 ? GF_I18N.se697ccc7 + count + ')' : GF_I18N.se808382d);
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
        if (!confirm(phone + GF_I18N.sc0a7e89e)) {
            return;
        }
        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: 'guardify_unblock_user', _wpnonce: nonce, phone: phone }, function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(180, function () { $(this).remove(); updateBulkBtn(); });
                GF.toast(phone + GF_I18N.saf20aca8, { type: 'success' });
            } else {
                GF.setLoading($btn, false);
                GF.toast(r.data || GF_I18N.saa9b9408, { type: 'error' });
            }
        });
    });

    $('#gf-bulk-unblock-btn').on('click', function () {
        var phones = $('.gf-blocked-check:checked').map(function () { return $(this).val(); }).get();
        if (!phones.length) { return; }
        if (!confirm(phones.length + GF_I18N.sb05fd735)) { return; }

        var $btn = $(this);
        GF.setLoading($btn, true);

        // Serialised rather than fired in parallel: each unblock is a write and
        // a shared host will start refusing a burst of twenty.
        var idx = 0;
        function nextUnblock() {
            if (idx >= phones.length) {
                GF.setLoading($btn, false);
                $btn.prop('disabled', true).text(GF_I18N.se808382d);
                GF.toast(phones.length + GF_I18N.sae62bbf1, { type: 'success' });
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
        var reason = $('#gf-block-reason').val().trim() || GF_I18N.s85a15116;

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
                GF.toast(r.data || GF_I18N.s311715cf, { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast(GF_I18N.sce6be67d, { type: 'error' });
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
                GF.toast(r.data || GF_I18N.s20f5c379, { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast(GF_I18N.sce6be67d, { type: 'error' });
        });
    });

    $(document).on('click', '.gf-remove-rule-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        if (!confirm(GF_I18N.saa68907c)) { return; }

        GF.setLoading($btn, true);
        $.post(ajaxurl, { action: 'guardify_remove_block_rule', _wpnonce: nonce, id: id }, function (r) {
            if (r.success) {
                $btn.closest('tr').fadeOut(180, function () { $(this).remove(); });
            } else {
                GF.setLoading($btn, false);
                GF.toast(GF_I18N.s024a3018, { type: 'error' });
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
                GF.toast(GF_I18N.s33da74fe, { type: 'error' });
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            GF.toast(GF_I18N.sce6be67d, { type: 'error' });
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
                GF.toast(GF_I18N.scf693656, { type: 'error' });
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
                    .text(ok ? (r.data.message || GF_I18N.sb06b1f19) : (r.data || GF_I18N.see36b123))
                    .show();
                if (ok) {
                    setTimeout(function () { location.reload(); }, 1500);
                }
            }).fail(function () {
                GF.setLoading($btn, false);
                GF.toast(GF_I18N.sce6be67d, { type: 'error' });
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
