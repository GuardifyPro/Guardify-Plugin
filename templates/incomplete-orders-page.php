<?php defined('ABSPATH') || exit; ?>
<div class="wrap gf-wrap">
    <div class="gf-header">
        <div class="gf-header-left">
            <div class="gf-logo">📋</div>
            <div>
                <h1 class="gf-page-title">ইনকমপ্লিট অর্ডার</h1>
                <p class="gf-text-muted" style="margin:0.25rem 0 0;font-size:0.8125rem;">চেকআউটে আসা কিন্তু অর্ডার সম্পন্ন না করা গ্রাহকদের তালিকা।</p>
            </div>
        </div>
    </div>

    <?php
    $per_page     = 20;
    $current_page = isset($_GET['gf_page']) ? max(1, absint($_GET['gf_page'])) : 1;
    $search       = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
    $offset       = ($current_page - 1) * $per_page;
    $total_count  = Guardify_Incomplete_Orders::get_pending_count($search);
    $orders       = Guardify_Incomplete_Orders::get_pending($per_page, $offset, $search);
    $total_pages  = max(1, (int) ceil($total_count / $per_page));
    $stats        = Guardify_Incomplete_Orders::get_stats();
    $recovery_rate = $stats->total > 0 ? round(($stats->recovered / $stats->total) * 100, 1) : 0;
    ?>

    <!-- Stats -->
    <div class="gf-stats-grid" style="margin-bottom:1.5rem;">
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-warning">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-2.5L13.73 4.5c-.77-.83-2.69-.83-3.46 0L3.34 16.5c-.77.83.19 2.5 1.73 2.5z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">পেন্ডিং</p>
                <p class="gf-stat-value"><?php echo esc_html($stats->pending); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-success">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">রিকভার্ড</p>
                <p class="gf-stat-value"><?php echo esc_html($stats->recovered); ?></p>
            </div>
        </div>
        <div class="gf-stat-card">
            <div class="gf-stat-icon gf-stat-icon-info">
                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <div>
                <p class="gf-stat-label">রিকভারি রেট</p>
                <p class="gf-stat-value"><?php echo esc_html($recovery_rate); ?>%</p>
            </div>
        </div>
    </div>

    <div class="gf-card">
        <div class="gf-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <h2 class="gf-card-title" style="display:flex;align-items:center;gap:0.5rem;">
                অসম্পন্ন অর্ডার <span class="gf-count-pill"><?php echo esc_html($total_count); ?></span>
            </h2>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <form method="get" style="display:flex;gap:0.375rem;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="guardify-incomplete" />
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" class="gf-input" placeholder="ফোন বা নাম খুঁজুন..." style="min-width:0;width:min(100%,200px);height:34px;font-size:0.8125rem;" />
                    <button type="submit" class="gf-btn gf-btn-secondary" style="height:34px;font-size:0.8125rem;padding:0 12px;">সার্চ</button>
                </form>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=guardify_export_incomplete'), 'guardify_export_nonce', 'nonce')); ?>" class="gf-btn gf-btn-secondary" style="height:34px;display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;padding:0 12px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    CSV এক্সপোর্ট
                </a>
                <button type="button" id="gf-io-show-statistics" class="gf-btn gf-btn-secondary" style="height:34px;display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;padding:0 12px;">
                    📊 পরিসংখ্যান
                </button>
            </div>
        </div>

        <!-- Bulk actions bar (hidden by default) -->
        <div id="gf-io-bulk-bar" style="display:none;padding:0.625rem 1.25rem;background:var(--gf-muted);border-bottom:1px solid var(--gf-border);align-items:center;gap:0.5rem;flex-wrap:wrap;">
            <span id="gf-io-selected-count" style="font-size:0.8125rem;font-weight:500;color:var(--gf-fg);"></span>
            <button type="button" id="gf-io-bulk-sms" class="gf-btn gf-btn-secondary" style="font-size:0.75rem;padding:0.25rem 0.75rem;">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                SMS পাঠান
            </button>
            <button type="button" id="gf-io-bulk-convert" class="gf-btn gf-btn-secondary" style="font-size:0.75rem;padding:0.25rem 0.75rem;">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                কনভার্ট
            </button>
            <button type="button" id="gf-io-bulk-delete" class="gf-btn gf-btn-secondary" style="font-size:0.75rem;padding:0.25rem 0.75rem;color:var(--gf-destructive);">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                মুছুন
            </button>
        </div>

        <div class="gf-card-body" style="padding:0;">
            <?php if (empty($orders)) : ?>
            <div class="gf-empty-state">
                <div class="gf-empty-state-icon">✅</div>
                <p class="gf-empty-state-title">কোনো ইনকমপ্লিট অর্ডার নেই</p>
                <p class="gf-empty-state-desc">
                    <?php echo $search ? 'সার্চের সাথে কোনো ফলাফল মেলেনি।' : 'সব ভালো! এই মুহূর্তে কোনো অসম্পন্ন অর্ডার নেই।'; ?>
                </p>
            </div>
            <?php else : ?>
            <div class="gf-table-wrap">
            <table class="gf-table">
                <thead>
                    <tr>
                        <th style="width:40px;"><input type="checkbox" id="gf-io-select-all" /></th>
                        <th>নাম</th>
                        <th>ফোন</th>
                        <th>শহর</th>
                        <th>কার্ট</th>
                        <th>মোট</th>
                        <th>সময়</th>
                        <th>গ্রাহক রিপোর্ট</th>
                        <th class="gf-col-action">অ্যাকশন</th>
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
                            if (count($names) > 2) $cart_summary .= ' +' . (count($names) - 2);
                            foreach ($cart as $ci) {
                                $cart_total += ($ci['price'] ?? 0) * ($ci['quantity'] ?? 1);
                            }
                        }
                        if ($row->cart_total > 0) $cart_total = $row->cart_total;
                    ?>
                    <tr id="gf-io-row-<?php echo esc_attr($row->id); ?>" data-id="<?php echo esc_attr($row->id); ?>" data-phone="<?php echo esc_attr($row->phone); ?>" data-name="<?php echo esc_attr($row->name); ?>">
                        <td><input type="checkbox" class="gf-io-check" value="<?php echo esc_attr($row->id); ?>" /></td>
                        <td><?php echo esc_html($row->name ?: '—'); ?></td>
                        <td><strong><?php echo esc_html($row->phone); ?></strong></td>
                        <td><?php echo esc_html($row->city ?: '—'); ?></td>
                        <td title="<?php echo esc_attr($cart_summary); ?>"><?php echo esc_html($cart_summary ?: '—'); ?></td>
                        <td style="white-space:nowrap;"><?php echo $cart_total ? '৳' . esc_html(number_format($cart_total)) : '—'; ?></td>
                        <td style="white-space:nowrap;"><?php echo esc_html(human_time_diff(strtotime($row->created_at)) . ' আগে'); ?></td>
                        <td class="gf-report-cell">
                            <?php
                            $incomplete = Guardify_Incomplete_Orders::get_instance();
                            echo $incomplete->get_report_data($row->phone);
                            ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:0.375rem;flex-wrap:wrap;">
                                <button type="button" class="gf-icon-btn gf-icon-btn-success gf-io-sms" data-id="<?php echo esc_attr($row->id); ?>" data-phone="<?php echo esc_attr($row->phone); ?>" data-name="<?php echo esc_attr($row->name); ?>" title="SMS পাঠান">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                </button>
                                <?php if (!empty($row->phone)) : ?>
                                <a href="tel:<?php echo esc_attr($row->phone); ?>" class="gf-icon-btn gf-icon-btn-info" title="কল করুন">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
                                </a>
                                <?php endif; ?>
                                <button type="button" class="gf-icon-btn gf-io-convert" data-id="<?php echo esc_attr($row->id); ?>" title="অর্ডারে কনভার্ট">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                                </button>
                                <button type="button" class="gf-icon-btn gf-icon-btn-danger gf-io-delete" data-id="<?php echo esc_attr($row->id); ?>" title="মুছুন">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
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
    <div class="gf-pagination">
        <?php
        $base_url = admin_url('admin.php?page=guardify-incomplete');
        if ($search) $base_url .= '&s=' . urlencode($search);

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

<!-- SMS Modal -->
<div id="gf-io-sms-modal" class="gf-modal-overlay" style="display:none;">
    <div class="gf-modal" style="max-width:520px;">
        <div class="gf-modal-header">
            <h3 style="margin:0;font-size:1rem;font-weight:600;">💬 রিকভারি SMS পাঠান</h3>
            <button type="button" class="gf-io-modal-close" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--gf-muted-fg);line-height:1;">&times;</button>
        </div>
        <div class="gf-modal-body">
            <p class="gf-text-muted" style="margin:0 0 0.75rem;font-size:0.8125rem;">প্লেসহোল্ডার: <code>{customer_name}</code>, <code>{product_name}</code>, <code>{order_total}</code>, <code>{siteurl}</code></p>
            <label class="gf-label">প্রাপক</label>
            <div id="gf-sms-recipients-list" style="background:#f8fafc;border:1px solid var(--gf-border);border-radius:6px;padding:0.5rem;margin-bottom:0.75rem;max-height:100px;overflow-y:auto;"></div>
            <input type="hidden" id="gf-sms-phone" />
            <label class="gf-label">মেসেজ</label>
            <textarea id="gf-sms-message" class="gf-input" rows="6" style="font-size:0.8125rem;"><?php
                $site_url = wp_parse_url(get_site_url(), PHP_URL_HOST);
                echo esc_textarea("আসসালামু আলাইকুম {customer_name},

আপনার কার্টে {product_name} রয়েছে, যা এখনও আপনার জন্য সংরক্ষিত আছে। 🛒

মোট মূল্য: {order_total}

অর্ডার সম্পন্ন করতে এখানে যান: " . get_permalink(wc_get_page_id('checkout')) . "

ধন্যবাদ,
{$site_url}");
            ?></textarea>
        </div>
        <div class="gf-modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" style="font-size:0.8125rem;">বাতিল</button>
            <button type="button" id="gf-sms-send" class="gf-btn gf-btn-primary" style="font-size:0.8125rem;">📤 পাঠান</button>
        </div>
    </div>
</div>

<!-- Convert Modal -->
<div id="gf-io-convert-modal" class="gf-modal-overlay" style="display:none;">
    <div class="gf-modal" style="max-width:400px;">
        <div class="gf-modal-header">
            <h3 style="margin:0;font-size:1rem;font-weight:600;">🔄 WC অর্ডার তৈরি করুন</h3>
            <button type="button" class="gf-io-modal-close" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--gf-muted-fg);line-height:1;">&times;</button>
        </div>
        <div class="gf-modal-body">
            <label class="gf-label">অর্ডার স্ট্যাটাস</label>
            <select id="gf-convert-status" class="gf-input">
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="on-hold">On Hold</option>
                <option value="completed">Completed</option>
            </select>
            <input type="hidden" id="gf-convert-id" />
            <input type="hidden" id="gf-convert-mode" value="single" />
            <div id="gf-convert-summary" style="margin-top:0.75rem;display:none;">
                <p class="gf-text-muted" style="font-size:0.8125rem;">আপনি <strong id="gf-convert-count">0</strong> টি ইনকমপ্লিট অর্ডার WooCommerce অর্ডারে রূপান্তর করতে যাচ্ছেন।</p>
            </div>
        </div>
        <div class="gf-modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" style="font-size:0.8125rem;">বাতিল</button>
            <button type="button" id="gf-convert-submit" class="gf-btn gf-btn-primary" style="font-size:0.8125rem;">🛒 তৈরি করুন</button>
        </div>
    </div>
</div>

<!-- Statistics Modal -->
<div id="gf-io-statistics-modal" class="gf-modal-overlay" style="display:none;">
    <div class="gf-modal" style="max-width:700px;">
        <div class="gf-modal-header">
            <h3 style="margin:0;font-size:1rem;font-weight:600;">📊 ইনকমপ্লিট অর্ডার পরিসংখ্যান</h3>
            <button type="button" class="gf-io-modal-close" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--gf-muted-fg);line-height:1;">&times;</button>
        </div>
        <div class="gf-modal-body">
            <?php $ds = Guardify_Incomplete_Orders::get_detailed_stats(); ?>

            <!-- Overview Cards -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:0.75rem;margin-bottom:1.25rem;">
                <div style="background:#fef3c7;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:1.5rem;">🛒</div>
                    <div style="font-size:0.75rem;color:#92400e;margin-top:0.25rem;">মোট ইনকমপ্লিট</div>
                    <div style="font-size:1.25rem;font-weight:700;color:#92400e;"><?php echo esc_html($ds->total); ?></div>
                </div>
                <div style="background:#d1fae5;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:1.5rem;">✅</div>
                    <div style="font-size:0.75rem;color:#065f46;margin-top:0.25rem;">রিকভার্ড</div>
                    <div style="font-size:1.25rem;font-weight:700;color:#065f46;"><?php echo esc_html($ds->recovered); ?></div>
                </div>
                <div style="background:#dbeafe;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:1.5rem;">📈</div>
                    <div style="font-size:0.75rem;color:#1e40af;margin-top:0.25rem;">রিকভারি রেট</div>
                    <div style="font-size:1.25rem;font-weight:700;color:#1e40af;"><?php echo esc_html($ds->recovery_rate); ?>%</div>
                </div>
                <div style="background:#fce7f3;border-radius:8px;padding:1rem;text-align:center;">
                    <div style="font-size:1.5rem;">💰</div>
                    <div style="font-size:0.75rem;color:#9d174d;margin-top:0.25rem;">রিকভার্ড রেভিনিউ</div>
                    <div style="font-size:1.25rem;font-weight:700;color:#9d174d;">৳<?php echo esc_html(number_format($ds->revenue_recovered)); ?></div>
                </div>
            </div>

            <!-- Daily/Weekly Timeline -->
            <h4 style="margin:0 0 0.75rem;font-size:0.875rem;font-weight:600;color:var(--gf-fg);">📅 সাম্প্রতিক কার্যকলাপ</h4>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.75rem;margin-bottom:1.25rem;">
                <div style="background:#f8fafc;border:1px solid var(--gf-border);border-radius:8px;padding:0.75rem;">
                    <div style="font-weight:600;font-size:0.8125rem;margin-bottom:0.5rem;">আজ</div>
                    <div style="font-size:0.75rem;color:var(--gf-muted-fg);">নতুন: <strong><?php echo esc_html($ds->today_new); ?></strong></div>
                    <div style="font-size:0.75rem;color:#16a34a;">কনভার্টেড: <strong><?php echo esc_html($ds->today_converted); ?></strong></div>
                </div>
                <div style="background:#f8fafc;border:1px solid var(--gf-border);border-radius:8px;padding:0.75rem;">
                    <div style="font-weight:600;font-size:0.8125rem;margin-bottom:0.5rem;">গতকাল</div>
                    <div style="font-size:0.75rem;color:var(--gf-muted-fg);">নতুন: <strong><?php echo esc_html($ds->yesterday_new); ?></strong></div>
                    <div style="font-size:0.75rem;color:#16a34a;">কনভার্টেড: <strong><?php echo esc_html($ds->yesterday_converted); ?></strong></div>
                </div>
                <div style="background:#f8fafc;border:1px solid var(--gf-border);border-radius:8px;padding:0.75rem;">
                    <div style="font-weight:600;font-size:0.8125rem;margin-bottom:0.5rem;">এই সপ্তাহ</div>
                    <div style="font-size:0.75rem;color:var(--gf-muted-fg);">নতুন: <strong><?php echo esc_html($ds->week_new); ?></strong></div>
                    <div style="font-size:0.75rem;color:#16a34a;">কনভার্টেড: <strong><?php echo esc_html($ds->week_converted); ?></strong></div>
                </div>
            </div>

            <!-- Top Abandoned Products -->
            <?php if (!empty($ds->top_products)) : ?>
            <h4 style="margin:0 0 0.75rem;font-size:0.875rem;font-weight:600;color:var(--gf-fg);">🏆 সবচেয়ে বেশি পরিত্যক্ত পণ্য</h4>
            <div style="background:#f8fafc;border:1px solid var(--gf-border);border-radius:8px;overflow:hidden;">
                <?php $rank = 1; foreach ($ds->top_products as $pname => $pcount) : ?>
                <div style="display:flex;align-items:center;justify-content:space-between;padding:0.5rem 0.75rem;<?php echo $rank < count($ds->top_products) ? 'border-bottom:1px solid var(--gf-border);' : ''; ?>">
                    <span style="font-size:0.8125rem;color:var(--gf-fg);">
                        <span style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--gf-primary);color:#fff;font-size:0.625rem;margin-right:0.5rem;"><?php echo $rank++; ?></span>
                        <?php echo esc_html(mb_strimwidth($pname, 0, 50, '...')); ?>
                    </span>
                    <span class="gf-badge gf-badge-muted"><?php echo esc_html($pcount); ?>×</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="gf-modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" style="font-size:0.8125rem;">বন্ধ</button>
        </div>
    </div>
</div>

<!-- Inline styles for report popup and new elements -->
<style>
.gf-customer-report {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}
.gf-btn-link {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    font-size: 1rem;
    line-height: 1;
}
.gf-btn-link:hover {
    transform: scale(1.15);
}
.gf-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 500;
    white-space: nowrap;
}
.gf-badge-success { background: #d1fae5; color: #065f46; }
.gf-badge-info { background: #dbeafe; color: #1e40af; }
.gf-badge-warning { background: #fef3c7; color: #92400e; }
.gf-badge-muted { background: #f1f5f9; color: #64748b; }

.gf-icon-btn-info {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid var(--gf-border);
    background: #eff6ff;
    color: #2563eb;
    cursor: pointer;
    text-decoration: none;
    transition: all 0.15s;
}
.gf-icon-btn-info:hover {
    background: #dbeafe;
    border-color: #93c5fd;
}

/* Report Popup */
.gf-report-popup {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999999;
    justify-content: center;
    align-items: center;
    backdrop-filter: blur(4px);
}
.gf-report-popup-content {
    background: #fff;
    width: 95%;
    max-width: 900px;
    border-radius: 12px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column;
    max-height: 85vh;
    overflow: hidden;
}
.gf-report-popup-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: linear-gradient(to right, #4f46e5, #6366f1);
    color: #fff;
    flex-shrink: 0;
}
.gf-report-popup-header h3 { margin: 0; font-size: 1rem; font-weight: 600; }
.gf-report-popup-close {
    width: 28px; height: 28px; border-radius: 50%;
    background: rgba(255,255,255,0.15); border: none; color: #fff;
    font-size: 18px; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: background 0.2s;
}
.gf-report-popup-close:hover { background: rgba(255,255,255,0.3); }
.gf-report-popup-body {
    overflow-y: auto;
    padding: 1.25rem 1.5rem;
    -webkit-overflow-scrolling: touch;
}
.gf-report-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}
.gf-report-stat {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.75rem;
    font-size: 0.8125rem;
}
.gf-report-stat strong { display: block; font-size: 1rem; margin-top: 0.25rem; }

.gf-report-orders-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.gf-report-orders-table th {
    background: #f8fafc;
    padding: 0.5rem;
    text-align: left;
    font-weight: 600;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
    font-size: 0.75rem;
}
.gf-report-orders-table td {
    padding: 0.5rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: top;
}
.gf-report-orders-table tr:hover td { background: #f8fafc; }

.gf-order-status {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    border-radius: 9999px;
    font-size: 0.6875rem;
    font-weight: 500;
}
.gf-order-status.status-completed { background: #d1fae5; color: #065f46; }
.gf-order-status.status-processing { background: #dbeafe; color: #1e40af; }
.gf-order-status.status-on-hold { background: #fef3c7; color: #92400e; }
.gf-order-status.status-cancelled { background: #fee2e2; color: #991b1b; }
.gf-order-status.status-refunded { background: #f1f5f9; color: #64748b; }
.gf-order-status.status-pending { background: #fef3c7; color: #92400e; }
.gf-order-status.status-failed { background: #fee2e2; color: #991b1b; }

.gf-delivery-report-section {
    margin-top: 1.25rem;
    padding-top: 1rem;
    border-top: 1px solid #e2e8f0;
}
.gf-delivery-report-section h4 {
    font-size: 0.875rem;
    font-weight: 600;
    margin: 0 0 0.75rem;
    color: #334155;
}

.gf-report-cell {
    min-width: 140px;
}
</style>

<script>
jQuery(function($){
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';

    // Select all / checkbox handling
    var $selectAll = $('#gf-io-select-all');
    var $bulkBar   = $('#gf-io-bulk-bar');

    function getSelectedIds() {
        return $('.gf-io-check:checked').map(function(){ return $(this).val(); }).get();
    }
    function updateBulkBar() {
        var ids = getSelectedIds();
        if (ids.length > 0) {
            $bulkBar.css('display', 'flex');
            $('#gf-io-selected-count').text(ids.length + ' টি সিলেক্টেড');
        } else {
            $bulkBar.hide();
        }
    }

    $selectAll.on('change', function(){
        $('.gf-io-check').prop('checked', this.checked);
        updateBulkBar();
    });
    $(document).on('change', '.gf-io-check', updateBulkBar);

    // Modal helpers
    function openModal(sel) { $(sel).css('display','flex'); }
    function closeModals() { $('.gf-modal-overlay').hide(); }
    $(document).on('click', '.gf-io-modal-close', closeModals);
    $(document).on('click', '.gf-modal-overlay', function(e){ if(e.target===this) closeModals(); });

    // --- Statistics Modal ---
    $('#gf-io-show-statistics').on('click', function(){
        openModal('#gf-io-statistics-modal');
    });

    // --- SMS ---
    var smsTarget = null;

    $(document).on('click', '.gf-io-sms', function(){
        smsTarget = { phone: $(this).data('phone'), name: $(this).data('name') || '' };
        $('#gf-sms-phone').val(smsTarget.phone);
        $('#gf-sms-recipients-list').html('<div style="padding:0.25rem 0.5rem;font-size:0.8125rem;border-left:3px solid var(--gf-primary);background:#f8fafc;border-radius:4px;">' + (smsTarget.name || 'গ্রাহক') + ' — ' + smsTarget.phone + '</div>');
        openModal('#gf-io-sms-modal');
    });

    $('#gf-io-bulk-sms').on('click', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        smsTarget = 'bulk';
        var html = '';
        ids.forEach(function(id) {
            var $row = $('#gf-io-row-' + id);
            var name = $row.data('name') || 'গ্রাহক';
            var phone = $row.data('phone');
            html += '<div style="padding:0.25rem 0.5rem;margin-bottom:0.25rem;font-size:0.8125rem;border-left:3px solid var(--gf-primary);background:#f8fafc;border-radius:4px;">' + name + ' — ' + phone + '</div>';
        });
        $('#gf-sms-recipients-list').html(html);
        $('#gf-sms-phone').val('');
        openModal('#gf-io-sms-modal');
    });

    $('#gf-sms-send').on('click', function(){
        var $btn = $(this);
        var msg  = $('#gf-sms-message').val();
        $btn.prop('disabled', true).text('পাঠানো হচ্ছে...');

        if (smsTarget === 'bulk') {
            var ids = getSelectedIds();
            var done = 0, fail = 0;
            ids.forEach(function(id){
                var $row = $('#gf-io-row-' + id);
                $.post(ajaxurl, {
                    action: 'guardify_send_recovery_sms',
                    _ajax_nonce: nonce,
                    phone: $row.data('phone'),
                    message: msg
                }, function(r){ if(r.success) done++; else fail++; })
                .always(function(){
                    if (done + fail === ids.length) {
                        $btn.prop('disabled', false).text('📤 পাঠান');
                        alert(done + ' টি SMS পাঠানো হয়েছে' + (fail ? ', ' + fail + ' টি ব্যর্থ' : ''));
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
            }, function(r){
                $btn.prop('disabled', false).text('📤 পাঠান');
                alert(r.success ? 'SMS পাঠানো হয়েছে!' : (r.data || 'ব্যর্থ'));
                closeModals();
            });
        }
    });

    // --- Convert ---
    $(document).on('click', '.gf-io-convert', function(){
        $('#gf-convert-id').val($(this).data('id'));
        $('#gf-convert-mode').val('single');
        $('#gf-convert-summary').hide();
        openModal('#gf-io-convert-modal');
    });

    $('#gf-io-bulk-convert').on('click', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#gf-convert-id').val(ids.join(','));
        $('#gf-convert-mode').val('bulk');
        $('#gf-convert-count').text(ids.length);
        $('#gf-convert-summary').show();
        openModal('#gf-io-convert-modal');
    });

    $('#gf-convert-submit').on('click', function(){
        var $btn   = $(this);
        var mode   = $('#gf-convert-mode').val();
        var status = $('#gf-convert-status').val();
        $btn.prop('disabled', true).text('তৈরি হচ্ছে...');

        if (mode === 'bulk') {
            var ids = $('#gf-convert-id').val().split(',').map(Number);
            $.post(ajaxurl, {
                action: 'guardify_bulk_convert_incomplete',
                _ajax_nonce: nonce,
                ids: ids,
                status: status
            }, function(r){
                $btn.prop('disabled', false).text('🛒 তৈরি করুন');
                if (r.success) {
                    alert(r.data.message);
                    location.reload();
                } else {
                    alert(r.data || 'ব্যর্থ');
                }
                closeModals();
            });
        } else {
            var id = parseInt($('#gf-convert-id').val());
            $.post(ajaxurl, {
                action: 'guardify_convert_incomplete',
                _ajax_nonce: nonce,
                id: id,
                status: status
            }, function(r){
                $btn.prop('disabled', false).text('🛒 তৈরি করুন');
                if (r.success) {
                    $('#gf-io-row-' + id).fadeOut();
                    alert(r.data.message);
                } else {
                    alert(r.data || 'ব্যর্থ');
                }
                closeModals();
            });
        }
    });

    // --- Delete ---
    $(document).on('click', '.gf-io-delete', function(){
        if (!confirm('মুছে ফেলতে চান?')) return;
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'guardify_delete_incomplete', _ajax_nonce: nonce, id: id }, function(r){
            if (r.success) $('#gf-io-row-' + id).fadeOut();
        });
    });

    $('#gf-io-bulk-delete').on('click', function(){
        var ids = getSelectedIds();
        if (!ids.length || !confirm(ids.length + ' টি রেকর্ড মুছে ফেলতে চান?')) return;
        $.post(ajaxurl, {
            action: 'guardify_bulk_delete_incomplete',
            _ajax_nonce: nonce,
            ids: ids
        }, function(r){
            if (r.success) location.reload();
            else alert(r.data || 'ব্যর্থ');
        });
    });
});
</script>
