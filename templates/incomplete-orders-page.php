<?php
/**
 * Guardify Pro — Incomplete orders page.
 *
 * Customers who reached checkout and left. Every row is a phone number worth
 * calling, so the per-row actions — SMS, call, convert — sit in the row itself
 * rather than behind a bulk-action dropdown.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$per_page      = 20;
$current_page  = isset($_GET['gf_page']) ? max(1, absint($_GET['gf_page'])) : 1;
$search        = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
$offset        = ($current_page - 1) * $per_page;
$total_count   = Guardify_Incomplete_Orders::get_pending_count($search);
$orders        = Guardify_Incomplete_Orders::get_pending($per_page, $offset, $search);
$total_pages   = max(1, (int) ceil($total_count / $per_page));
$stats         = Guardify_Incomplete_Orders::get_stats();
$recovery_rate = $stats->total > 0 ? round(($stats->recovered / $stats->total) * 100, 1) : 0;
$incomplete    = Guardify_Incomplete_Orders::get_instance();
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2l1.5 3h12L21 8l-1.6 8H8L6 2H3"/><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title"><?php esc_html_e('ইনকমপ্লিট অর্ডার', 'guardify-pro'); ?></h1>
                <p class="gf-page-desc"><?php esc_html_e('চেকআউটে এসেছে কিন্তু অর্ডার শেষ করেনি — ফোন বা SMS দিয়ে ফিরিয়ে আনার সুযোগ।', 'guardify-pro'); ?></p>
            </div>
        </div>
        <div class="gf-page-header-actions">
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=guardify_export_incomplete'), 'guardify_export_nonce', 'nonce')); ?>" class="gf-btn gf-btn-secondary gf-btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                <?php esc_html_e('CSV এক্সপোর্ট', 'guardify-pro'); ?>
            </a>
            <button type="button" id="gf-io-show-statistics" class="gf-btn gf-btn-secondary gf-btn-sm" data-gf-open="#gf-io-statistics-modal"><?php esc_html_e('পরিসংখ্যান', 'guardify-pro'); ?></button>
        </div>
    </div>

    <div class="gf-stats-grid">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('পেন্ডিং', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($stats->pending)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="9"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('রিকভার্ড', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($stats->recovered)); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 7h8v8"/><path d="M21 7l-8 8-4-4-6 6"/></svg>
            </div>
            <div class="gf-stat-body">
                <p class="gf-stat-label"><?php esc_html_e('রিকভারি রেট', 'guardify-pro'); ?></p>
                <p class="gf-stat-value"><?php echo esc_html($recovery_rate); ?>%</p>
            </div>
        </div>
    </div>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">
                    <?php esc_html_e('অসম্পন্ন অর্ডার', 'guardify-pro'); ?>
                    <span class="gf-count-pill"><?php echo esc_html(number_format_i18n($total_count)); ?></span>
                </h2>
                <p class="gf-card-desc"><?php esc_html_e('সবচেয়ে নতুন উপরে। গ্রাহক রিপোর্ট কলামে ক্লিক করলে তার আগের অর্ডার ও ডেলিভারি রেকর্ড দেখা যাবে।', 'guardify-pro'); ?></p>
            </div>
            <div class="gf-card-header-actions">
                <form method="get" class="gf-row">
                    <input type="hidden" name="page" value="guardify-incomplete" />
                    <label class="gf-sr-only" for="gf-io-search"><?php esc_html_e('ফোন বা নাম খুঁজুন', 'guardify-pro'); ?></label>
                    <input type="search" id="gf-io-search" name="s" value="<?php echo esc_attr($search); ?>" class="gf-input" placeholder="<?php echo esc_attr__('ফোন বা নাম খুঁজুন…', 'guardify-pro'); ?>" style="width: min(100%, 220px);" />
                    <button type="submit" class="gf-btn gf-btn-secondary gf-btn-sm"><?php esc_html_e('সার্চ', 'guardify-pro'); ?></button>
                    <?php if ($search !== '') : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-incomplete')); ?>" class="gf-btn gf-btn-ghost gf-btn-sm"><?php esc_html_e('সার্চ বাতিল', 'guardify-pro'); ?></a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div id="gf-io-bulk-bar" class="gf-row" style="display:none;padding:0.625rem 1.375rem;background:var(--gf-accent);border-bottom:1px solid var(--gf-border);">
            <strong id="gf-io-selected-count" class="gf-text-sm"></strong>
            <button type="button" id="gf-io-bulk-sms" class="gf-btn gf-btn-secondary gf-btn-sm"><?php esc_html_e('SMS পাঠান', 'guardify-pro'); ?></button>
            <button type="button" id="gf-io-bulk-convert" class="gf-btn gf-btn-secondary gf-btn-sm"><?php esc_html_e('অর্ডারে কনভার্ট', 'guardify-pro'); ?></button>
            <button type="button" id="gf-io-bulk-delete" class="gf-btn gf-btn-ghost gf-btn-sm gf-text-danger"><?php esc_html_e('মুছুন', 'guardify-pro'); ?></button>
        </div>

        <div class="gf-card-body gf-flush">
            <?php if (empty($orders)) : ?>
            <div class="gf-empty-state">
                <div class="gf-empty-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <p class="gf-empty-state-title">
                    <?php echo $search !== '' ? esc_html(__('কোনো ফলাফল মেলেনি', 'guardify-pro')) : esc_html(__('কোনো অসম্পন্ন অর্ডার নেই', 'guardify-pro')); ?>
                </p>
                <p class="gf-empty-state-desc">
                    <?php
                    echo $search !== ''
                        ? esc_html(__('অন্য ফোন নম্বর বা নাম দিয়ে চেষ্টা করুন।', 'guardify-pro'))
                        : esc_html(__('এই মুহূর্তে সবাই চেকআউট শেষ করছেন। ফিচারটি চালু আছে কি না সেটিংস পেজ থেকে দেখে নিন।', 'guardify-pro'));
                    ?>
                </p>
            </div>
            <?php else : ?>
            <div class="gf-table-wrap">
                <table class="gf-table gf-table-stack">
                    <thead>
                        <tr>
                            <th class="gf-col-check" data-label="">
                                <input type="checkbox" id="gf-io-select-all" class="gf-check" aria-label="<?php echo esc_attr__('সব সিলেক্ট করুন', 'guardify-pro'); ?>" />
                            </th>
                            <th><?php esc_html_e('নাম', 'guardify-pro'); ?></th>
                            <th><?php esc_html_e('ফোন', 'guardify-pro'); ?></th>
                            <th><?php esc_html_e('শহর', 'guardify-pro'); ?></th>
                            <th><?php esc_html_e('কার্ট', 'guardify-pro'); ?></th>
                            <th class="gf-table-num"><?php esc_html_e('মোট', 'guardify-pro'); ?></th>
                            <th><?php esc_html_e('সময়', 'guardify-pro'); ?></th>
                            <th><?php esc_html_e('গ্রাহক রিপোর্ট', 'guardify-pro'); ?></th>
                            <th class="gf-col-action" data-label=""><?php esc_html_e('অ্যাকশন', 'guardify-pro'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $row) :
                            $cart = json_decode($row->cart_data, true);
                            $cart_summary = '';
                            $cart_total   = 0;
                            if (!empty($cart)) {
                                $names = array_column($cart, 'name');
                                $cart_summary = implode(', ', array_slice($names, 0, 2));
                                if (count($names) > 2) {
                                    $cart_summary .= ' +' . (count($names) - 2);
                                }
                                foreach ($cart as $ci) {
                                    $cart_total += (isset($ci['price']) ? $ci['price'] : 0) * (isset($ci['quantity']) ? $ci['quantity'] : 1);
                                }
                            }
                            if ($row->cart_total > 0) {
                                $cart_total = $row->cart_total;
                            }
                        ?>
                        <tr id="gf-io-row-<?php echo esc_attr($row->id); ?>"
                            data-id="<?php echo esc_attr($row->id); ?>"
                            data-phone="<?php echo esc_attr($row->phone); ?>"
                            data-name="<?php echo esc_attr($row->name); ?>">
                            <td>
                                <input type="checkbox" class="gf-check gf-io-check" value="<?php echo esc_attr($row->id); ?>"
                                    aria-label="<?php echo esc_attr($row->phone); ?>" />
                            </td>
                            <td><?php echo esc_html($row->name ?: '—'); ?></td>
                            <td><strong class="gf-mono"><?php echo esc_html($row->phone); ?></strong></td>
                            <td class="gf-text-muted"><?php echo esc_html($row->city ?: '—'); ?></td>
                            <td title="<?php echo esc_attr($cart_summary); ?>"><?php echo esc_html($cart_summary ?: '—'); ?></td>
                            <td class="gf-table-num gf-table-nowrap">
                                <?php echo $cart_total ? '৳' . esc_html(number_format($cart_total)) : '—'; ?>
                            </td>
                            <td class="gf-table-nowrap gf-text-muted">
                                <?php echo esc_html(human_time_diff(strtotime($row->created_at)) . __(' আগে', 'guardify-pro')); ?>
                            </td>
                            <td class="gf-report-cell">
                                <?php
                                // Report markup is assembled and escaped inside the module; it
                                // carries a popup and price formatting that must stay markup.
                                echo $incomplete->get_report_data($row->phone);
                                ?>
                            </td>
                            <td class="gf-col-action">
                                <div class="gf-row" style="gap: 0.25rem;">
                                    <button type="button" class="gf-icon-btn gf-icon-btn-success gf-io-sms gf-tooltip"
                                        data-tooltip="<?php echo esc_attr__('রিকভারি SMS পাঠান', 'guardify-pro'); ?>"
                                        data-id="<?php echo esc_attr($row->id); ?>"
                                        data-phone="<?php echo esc_attr($row->phone); ?>"
                                        data-name="<?php echo esc_attr($row->name); ?>"
                                        aria-label="<?php echo esc_attr__('রিকভারি SMS পাঠান', 'guardify-pro'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                    </button>
                                    <?php if (!empty($row->phone)) : ?>
                                    <a href="tel:<?php echo esc_attr($row->phone); ?>" class="gf-icon-btn gf-icon-btn-info gf-tooltip"
                                        data-tooltip="<?php echo esc_attr__('কল করুন', 'guardify-pro'); ?>" aria-label="<?php echo esc_attr__('কল করুন', 'guardify-pro'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                                    </a>
                                    <?php endif; ?>
                                    <button type="button" class="gf-icon-btn gf-io-convert gf-tooltip"
                                        data-tooltip="<?php echo esc_attr__('WooCommerce অর্ডার তৈরি করুন', 'guardify-pro'); ?>"
                                        data-id="<?php echo esc_attr($row->id); ?>"
                                        aria-label="<?php echo esc_attr__('অর্ডারে কনভার্ট', 'guardify-pro'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                                    </button>
                                    <button type="button" class="gf-icon-btn gf-icon-btn-danger gf-io-delete gf-tooltip"
                                        data-tooltip="<?php echo esc_attr__('রেকর্ড মুছুন', 'guardify-pro'); ?>"
                                        data-id="<?php echo esc_attr($row->id); ?>"
                                        aria-label="<?php echo esc_attr__('মুছুন', 'guardify-pro'); ?>">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                    </button>
                                </div>
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
        $base_url = admin_url('admin.php?page=guardify-incomplete');
        if ($search) {
            $base_url .= '&s=' . urlencode($search);
        }

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

    <!-- ── SMS modal ─────────────────────────────────────────────────────── -->
    <div id="gf-io-sms-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-io-sms-title" style="display:none;">
        <div class="gf-modal">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-io-sms-title"><?php esc_html_e('রিকভারি SMS পাঠান', 'guardify-pro'); ?></h2>
                <button type="button" class="gf-modal-close gf-io-modal-close" data-gf-close aria-label="<?php echo esc_attr__('বন্ধ করুন', 'guardify-pro'); ?>">&times;</button>
            </div>
            <div class="gf-modal-body">
                <div class="gf-field">
                    <span class="gf-label"><?php esc_html_e('প্রাপক', 'guardify-pro'); ?></span>
                    <div id="gf-sms-recipients-list" class="gf-stack-sm" style="max-height:120px;overflow-y:auto;padding:0.5rem;border:1px solid var(--gf-border);border-radius:var(--gf-radius);background:var(--gf-surface);"></div>
                    <input type="hidden" id="gf-sms-phone" />
                </div>
                <div class="gf-field">
                    <label class="gf-label" for="gf-sms-message"><?php esc_html_e('মেসেজ', 'guardify-pro'); ?></label>
                    <textarea id="gf-sms-message" class="gf-input" rows="7"><?php
                        $site_host = wp_parse_url(get_site_url(), PHP_URL_HOST);

                        // One string with two placeholders rather than three concatenated
                        // pieces. A translator needs the whole message to reorder it, and the
                        // checkout URL and the shop's own hostname must stay *outside* the
                        // msgid — a msgid containing this site's hostname is a key no
                        // catalogue could ever match, so the message would silently never
                        // translate on any site but the one it was extracted from.
                        //
                        // {customer_name}, {product_name} and {order_total} are the plugin's
                        // own merge tags, filled per recipient when the SMS is sent, and are
                        // deliberately left in the text for the merchant to move around.
                        printf(
                            /* translators: 1: checkout URL, 2: the shop's domain name */
                            // On one line, unattractive as that is: bin/make-pot.php scans
                            // line by line, and a msgid built by concatenation across lines
                            // is one the catalogue never learns about.
                            esc_textarea(__("আসসালামু আলাইকুম {customer_name},\n\nআপনার কার্টে {product_name} রয়েছে, যা এখনও আপনার জন্য সংরক্ষিত আছে।\n\nমোট মূল্য: {order_total}\n\nঅর্ডার সম্পন্ন করতে এখানে যান: %1\$s\n\nধন্যবাদ,\n%2\$s", 'guardify-pro')),
                            esc_textarea(get_permalink(wc_get_page_id('checkout'))),
                            esc_textarea($site_host)
                        );
                    ?></textarea>
                    <span class="gf-help">
                        <?php
                        printf(
                            /* translators: %s is a comma-separated list of placeholder tokens */
                            esc_html__('প্লেসহোল্ডার: %s — প্রতিটি গ্রাহকের তথ্য দিয়ে আপনা-আপনি বসে যাবে।', 'guardify-pro'),
                            implode(', ', array_map(function ($gf_token) {
                                return '<code>' . esc_html($gf_token) . '</code>';
                            }, ['{customer_name}', '{product_name}', '{order_total}', '{siteurl}']))
                        );
                        ?>
                    </span>
                </div>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" data-gf-close><?php esc_html_e('বাতিল', 'guardify-pro'); ?></button>
                <button type="button" id="gf-sms-send" class="gf-btn gf-btn-primary"><?php esc_html_e('পাঠান', 'guardify-pro'); ?></button>
            </div>
        </div>
    </div>

    <!-- ── Convert modal ─────────────────────────────────────────────────── -->
    <div id="gf-io-convert-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-io-convert-title" style="display:none;">
        <div class="gf-modal gf-modal-sm">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-io-convert-title"><?php esc_html_e('WooCommerce অর্ডার তৈরি করুন', 'guardify-pro'); ?></h2>
                <button type="button" class="gf-modal-close gf-io-modal-close" data-gf-close aria-label="<?php echo esc_attr__('বন্ধ করুন', 'guardify-pro'); ?>">&times;</button>
            </div>
            <div class="gf-modal-body">
                <div class="gf-field">
                    <label class="gf-label" for="gf-convert-status"><?php esc_html_e('অর্ডার স্ট্যাটাস', 'guardify-pro'); ?></label>
                    <select id="gf-convert-status" class="gf-select">
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="on-hold">On Hold</option>
                        <option value="completed">Completed</option>
                    </select>
                    <span class="gf-help"><?php esc_html_e('গ্রাহকের সাথে ফোনে কথা বলে নিশ্চিত হলে Processing দিন; এখনো নিশ্চিত না হলে Pending।', 'guardify-pro'); ?></span>
                </div>
                <input type="hidden" id="gf-convert-id" />
                <input type="hidden" id="gf-convert-mode" value="single" />
                <p id="gf-convert-summary" class="gf-help" style="display:none;">
                    <?php
                    printf(
                        /* translators: %s is a count the page fills in, so it stays markup */
                        esc_html__('আপনি %s টি ইনকমপ্লিট অর্ডার WooCommerce অর্ডারে রূপান্তর করতে যাচ্ছেন।', 'guardify-pro'),
                        '<strong id="gf-convert-count">0</strong>'
                    );
                    ?>
                </p>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" data-gf-close><?php esc_html_e('বাতিল', 'guardify-pro'); ?></button>
                <button type="button" id="gf-convert-submit" class="gf-btn gf-btn-primary"><?php esc_html_e('তৈরি করুন', 'guardify-pro'); ?></button>
            </div>
        </div>
    </div>

    <!-- ── Statistics modal ──────────────────────────────────────────────── -->
    <div id="gf-io-statistics-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-io-stats-title" style="display:none;">
        <div class="gf-modal gf-modal-lg">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-io-stats-title"><?php esc_html_e('ইনকমপ্লিট অর্ডার পরিসংখ্যান', 'guardify-pro'); ?></h2>
                <button type="button" class="gf-modal-close gf-io-modal-close" data-gf-close aria-label="<?php echo esc_attr__('বন্ধ করুন', 'guardify-pro'); ?>">&times;</button>
            </div>
            <div class="gf-modal-body">
                <?php $ds = Guardify_Incomplete_Orders::get_detailed_stats(); ?>

                <div class="gf-stats-grid" style="margin-bottom: 0;">
                    <div class="gf-stat-card">
                        <div class="gf-stat-body">
                            <p class="gf-stat-label"><?php esc_html_e('মোট ইনকমপ্লিট', 'guardify-pro'); ?></p>
                            <p class="gf-stat-value"><?php echo esc_html(number_format_i18n($ds->total)); ?></p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-body">
                            <p class="gf-stat-label"><?php esc_html_e('রিকভার্ড', 'guardify-pro'); ?></p>
                            <p class="gf-stat-value gf-text-success"><?php echo esc_html(number_format_i18n($ds->recovered)); ?></p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-body">
                            <p class="gf-stat-label"><?php esc_html_e('রিকভারি রেট', 'guardify-pro'); ?></p>
                            <p class="gf-stat-value"><?php echo esc_html($ds->recovery_rate); ?>%</p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-body">
                            <p class="gf-stat-label"><?php esc_html_e('রিকভার্ড রেভিনিউ', 'guardify-pro'); ?></p>
                            <p class="gf-stat-value">৳<?php echo esc_html(number_format($ds->revenue_recovered)); ?></p>
                        </div>
                    </div>
                </div>

                <div class="gf-section">
                    <h3 class="gf-section-title"><?php esc_html_e('সাম্প্রতিক কার্যকলাপ', 'guardify-pro'); ?></h3>
                    <div class="gf-section-body">
                        <table class="gf-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('সময়কাল', 'guardify-pro'); ?></th>
                                    <th class="gf-table-num"><?php esc_html_e('নতুন', 'guardify-pro'); ?></th>
                                    <th class="gf-table-num"><?php esc_html_e('কনভার্টেড', 'guardify-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="gf-text-strong"><?php esc_html_e('আজ', 'guardify-pro'); ?></td>
                                    <td class="gf-table-num"><?php echo esc_html(number_format_i18n($ds->today_new)); ?></td>
                                    <td class="gf-table-num gf-text-success"><?php echo esc_html(number_format_i18n($ds->today_converted)); ?></td>
                                </tr>
                                <tr>
                                    <td class="gf-text-strong"><?php esc_html_e('গতকাল', 'guardify-pro'); ?></td>
                                    <td class="gf-table-num"><?php echo esc_html(number_format_i18n($ds->yesterday_new)); ?></td>
                                    <td class="gf-table-num gf-text-success"><?php echo esc_html(number_format_i18n($ds->yesterday_converted)); ?></td>
                                </tr>
                                <tr>
                                    <td class="gf-text-strong"><?php esc_html_e('এই সপ্তাহ', 'guardify-pro'); ?></td>
                                    <td class="gf-table-num"><?php echo esc_html(number_format_i18n($ds->week_new)); ?></td>
                                    <td class="gf-table-num gf-text-success"><?php echo esc_html(number_format_i18n($ds->week_converted)); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (!empty($ds->top_products)) : ?>
                <div class="gf-section">
                    <h3 class="gf-section-title"><?php esc_html_e('সবচেয়ে বেশি পরিত্যক্ত পণ্য', 'guardify-pro'); ?></h3>
                    <p class="gf-section-desc"><?php esc_html_e('একই পণ্য বারবার কার্টে ফেলে রাখা হলে দাম, ডেলিভারি চার্জ বা স্টক তথ্য দেখে নিন।', 'guardify-pro'); ?></p>
                    <div class="gf-section-body">
                        <table class="gf-table">
                            <thead>
                                <tr>
                                    <th style="width:48px;" class="gf-table-num">#</th>
                                    <th><?php esc_html_e('পণ্য', 'guardify-pro'); ?></th>
                                    <th class="gf-table-num"><?php esc_html_e('বার', 'guardify-pro'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rank = 1; foreach ($ds->top_products as $pname => $pcount) : ?>
                                <tr>
                                    <td class="gf-table-num gf-text-muted"><?php echo esc_html(number_format_i18n($rank++)); ?></td>
                                    <td><?php echo esc_html(mb_strimwidth($pname, 0, 60, '…')); ?></td>
                                    <td class="gf-table-num"><span class="gf-badge gf-badge-muted"><?php echo esc_html(number_format_i18n($pcount)); ?>×</span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" data-gf-close><?php esc_html_e('বন্ধ', 'guardify-pro'); ?></button>
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
        's165a17c3' => __(' টি সিলেক্টেড', 'guardify-pro'),
        's479341f8' => __(' টি SMS পাঠানো হয়েছে', 'guardify-pro'),
        's48abae69' => __('গ্রাহক', 'guardify-pro'),
        's670ad97e' => __('এই রেকর্ড মুছে ফেলতে চান? গ্রাহকের ফোন নম্বর ও কার্ট তথ্য আর ফেরানো যাবে না।', 'guardify-pro'),
        's831a65b9' => __(' টি রেকর্ড মুছে ফেলতে চান?', 'guardify-pro'),
        's85d77d76' => __(' টি ব্যর্থ', 'guardify-pro'),
        's8ec376cc' => __('মোছা যায়নি।', 'guardify-pro'),
        'sb9a2dd4d' => __('SMS পাঠানো হয়েছে।', 'guardify-pro'),
        'scb122681' => __('SMS পাঠানো যায়নি।', 'guardify-pro'),
        'sf7159368' => __('কনভার্ট করা যায়নি।', 'guardify-pro'),
    ]); ?>;

jQuery(function ($) {
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';
    var GF    = window.Guardify;

    var $selectAll = $('#gf-io-select-all');
    var $bulkBar   = $('#gf-io-bulk-bar');

    function getSelectedIds() {
        return $('.gf-io-check:checked').map(function () { return $(this).val(); }).get();
    }

    function updateBulkBar() {
        var ids = getSelectedIds();
        $bulkBar.css('display', ids.length ? 'flex' : 'none');
        $('#gf-io-selected-count').text(ids.length + GF_I18N.s165a17c3);
    }

    $selectAll.on('change', function () {
        $('.gf-io-check').prop('checked', this.checked);
        updateBulkBar();
    });
    $(document).on('change', '.gf-io-check', updateBulkBar);

    function closeModals() {
        GF.closeAllOverlays();
    }

    /* ── Recipient list ────────────────────────────────────────────────────
       Names come from the row's data attributes, which are customer-entered.
       Built as text nodes so a name containing markup stays a name. */

    function renderRecipients(rows) {
        var $list = $('#gf-sms-recipients-list').empty();
        rows.forEach(function (r) {
            $list.append(
                $('<div>').addClass('gf-text-sm').css({
                    padding: '0.25rem 0.5rem',
                    borderLeft: '3px solid var(--gf-primary)',
                    borderRadius: '4px',
                    background: 'var(--gf-card)'
                }).text((r.name || GF_I18N.s48abae69) + ' — ' + r.phone)
            );
        });
    }

    /* ── SMS ──────────────────────────────────────────────────────────── */

    var smsTarget = null;

    $(document).on('click', '.gf-io-sms', function () {
        smsTarget = { phone: $(this).data('phone'), name: $(this).data('name') || '' };
        $('#gf-sms-phone').val(smsTarget.phone);
        renderRecipients([smsTarget]);
        GF.openModal('#gf-io-sms-modal');
    });

    $('#gf-io-bulk-sms').on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) { return; }
        smsTarget = 'bulk';
        renderRecipients(ids.map(function (id) {
            var $row = $('#gf-io-row-' + id);
            return { name: $row.data('name'), phone: $row.data('phone') };
        }));
        $('#gf-sms-phone').val('');
        GF.openModal('#gf-io-sms-modal');
    });

    $('#gf-sms-send').on('click', function () {
        var $btn = $(this);
        var msg  = $('#gf-sms-message').val();
        GF.setLoading($btn, true);

        if (smsTarget === 'bulk') {
            var ids = getSelectedIds();
            var done = 0, fail = 0;
            ids.forEach(function (id) {
                var $row = $('#gf-io-row-' + id);
                $.post(ajaxurl, {
                    action: 'guardify_send_recovery_sms',
                    _ajax_nonce: nonce,
                    phone: $row.data('phone'),
                    message: msg
                }, function (r) { if (r.success) { done++; } else { fail++; } })
                .always(function () {
                    if (done + fail === ids.length) {
                        GF.setLoading($btn, false);
                        GF.toast(done + GF_I18N.s479341f8 + (fail ? ', ' + fail + GF_I18N.s85d77d76 : '') + '।',
                            { type: fail ? 'warning' : 'success' });
                        closeModals();
                    }
                });
            });
        } else {
            $.post(ajaxurl, {
                action: 'guardify_send_recovery_sms',
                _ajax_nonce: nonce,
                phone: smsTarget.phone,
                message: msg
            }, function (r) {
                GF.setLoading($btn, false);
                GF.toast(r.success ? GF_I18N.sb9a2dd4d : (r.data || GF_I18N.scb122681),
                    { type: r.success ? 'success' : 'error' });
                closeModals();
            });
        }
    });

    /* ── Convert ──────────────────────────────────────────────────────── */

    $(document).on('click', '.gf-io-convert', function () {
        $('#gf-convert-id').val($(this).data('id'));
        $('#gf-convert-mode').val('single');
        $('#gf-convert-summary').hide();
        GF.openModal('#gf-io-convert-modal');
    });

    $('#gf-io-bulk-convert').on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length) { return; }
        $('#gf-convert-id').val(ids.join(','));
        $('#gf-convert-mode').val('bulk');
        $('#gf-convert-count').text(ids.length);
        $('#gf-convert-summary').show();
        GF.openModal('#gf-io-convert-modal');
    });

    $('#gf-convert-submit').on('click', function () {
        var $btn   = $(this);
        var mode   = $('#gf-convert-mode').val();
        var status = $('#gf-convert-status').val();
        GF.setLoading($btn, true);

        if (mode === 'bulk') {
            var ids = $('#gf-convert-id').val().split(',').map(Number);
            $.post(ajaxurl, {
                action: 'guardify_bulk_convert_incomplete',
                _ajax_nonce: nonce,
                ids: ids,
                status: status
            }, function (r) {
                GF.setLoading($btn, false);
                if (r.success) {
                    GF.toast(r.data.message, { type: 'success' });
                    location.reload();
                } else {
                    GF.toast(r.data || GF_I18N.sf7159368, { type: 'error' });
                }
                closeModals();
            });
        } else {
            var id = parseInt($('#gf-convert-id').val(), 10);
            $.post(ajaxurl, {
                action: 'guardify_convert_incomplete',
                _ajax_nonce: nonce,
                id: id,
                status: status
            }, function (r) {
                GF.setLoading($btn, false);
                if (r.success) {
                    $('#gf-io-row-' + id).fadeOut(180);
                    GF.toast(r.data.message, { type: 'success' });
                } else {
                    GF.toast(r.data || GF_I18N.sf7159368, { type: 'error' });
                }
                closeModals();
            });
        }
    });

    /* ── Delete ───────────────────────────────────────────────────────── */

    $(document).on('click', '.gf-io-delete', function () {
        if (!confirm(GF_I18N.s670ad97e)) {
            return;
        }
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'guardify_delete_incomplete', _ajax_nonce: nonce, id: id }, function (r) {
            if (r.success) {
                $('#gf-io-row-' + id).fadeOut(180, updateBulkBar);
            } else {
                GF.toast(r.data || GF_I18N.s8ec376cc, { type: 'error' });
            }
        });
    });

    $('#gf-io-bulk-delete').on('click', function () {
        var ids = getSelectedIds();
        if (!ids.length || !confirm(ids.length + GF_I18N.s831a65b9)) { return; }
        var $btn = $(this);
        GF.setLoading($btn, true);
        $.post(ajaxurl, {
            action: 'guardify_bulk_delete_incomplete',
            _ajax_nonce: nonce,
            ids: ids
        }, function (r) {
            if (r.success) {
                location.reload();
            } else {
                GF.setLoading($btn, false);
                GF.toast(r.data || GF_I18N.s8ec376cc, { type: 'error' });
            }
        });
    });
});
</script>
