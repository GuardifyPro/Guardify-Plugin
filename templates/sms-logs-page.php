<?php
/**
 * Guardify Pro — SMS log page.
 *
 * The log is read from the engine, not from a local table, so it is the
 * authoritative record of what was billed. Cost is shown per row because a
 * merchant on a prepaid balance wants to know which messages are spending it.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$sms = Guardify_SMS_Logs::get_instance();

$per_page     = 20;
$current_page = isset($_GET['gf_page']) ? max(1, absint($_GET['gf_page'])) : 1;
$result       = $sms->fetch_logs($current_page, $per_page);

$logs        = [];
$total       = 0;
$total_pages = 1;
$error       = '';

if ($result['success']) {
    $logs        = $result['logs'];
    $total       = $result['total'];
    $total_pages = max(1, (int) ceil($total / $per_page));
} else {
    $error = $result['message'];
}
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title">SMS লগস</h1>
                <p class="gf-page-desc">পাঠানো সব SMS-এর তালিকা, স্ট্যাটাস ও খরচ।</p>
            </div>
        </div>
        <div class="gf-page-header-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-pro')); ?>" class="gf-btn gf-btn-ghost gf-btn-sm">সেটিংস ↗</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-sms-logs')); ?>" class="gf-btn gf-btn-secondary gf-btn-sm">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                রিফ্রেশ
            </a>
        </div>
    </div>

    <?php if ($error) : ?>
    <div class="gf-alert gf-alert-error gf-mb-3" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v4m0 4h.01"/></svg>
        <div>
            <strong class="gf-alert-title">লগ আনা যায়নি</strong>
            <?php echo esc_html($error); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">
                    পাঠানো SMS
                    <?php if ($total > 0) : ?>
                        <span class="gf-count-pill"><?php echo esc_html(number_format_i18n($total)); ?></span>
                    <?php endif; ?>
                </h2>
                <p class="gf-card-desc">সময় বাংলাদেশ (ঢাকা) সময় অনুযায়ী দেখানো হয়েছে।</p>
            </div>
            <div class="gf-card-header-actions">
                <label class="gf-sr-only" for="gf-sms-search">SMS সার্চ</label>
                <input type="search" id="gf-sms-search" class="gf-input" placeholder="ফোন বা মেসেজ খুঁজুন…" style="width: min(100%, 260px);" />
            </div>
        </div>
        <div class="gf-card-body gf-flush">
            <?php if (empty($logs) && empty($error)) : ?>
            <div class="gf-empty-state">
                <div class="gf-empty-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v12H7l-3 3z"/></svg>
                </div>
                <p class="gf-empty-state-title">কোনো SMS লগ নেই</p>
                <p class="gf-empty-state-desc">এখনো কোনো SMS পাঠানো হয়নি। SMS নোটিফিকেশন ও OTP সেটিংস পেজ থেকে চালু করুন।</p>
                <div class="gf-empty-state-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-pro')); ?>" class="gf-btn gf-btn-primary gf-btn-sm">সেটিংসে যান</a>
                </div>
            </div>
            <?php elseif (!empty($logs)) : ?>
            <div id="gf-sms-no-results" class="gf-no-data" style="display:none;">
                <p class="gf-text-strong">কোনো ফলাফল পাওয়া যায়নি</p>
                <p class="gf-help">আপনার সার্চের সাথে কোনো SMS মেলেনি।</p>
            </div>
            <div class="gf-table-wrap">
                <table class="gf-table gf-table-stack" id="gf-sms-table">
                    <thead>
                        <tr>
                            <th style="width:56px;">#</th>
                            <th style="width:140px;">ফোন</th>
                            <th>মেসেজ</th>
                            <th style="width:100px;">স্ট্যাটাস</th>
                            <th style="width:170px;">সময়</th>
                            <th class="gf-table-center" style="width:70px;">পার্টস</th>
                            <th class="gf-table-num" style="width:90px;">খরচ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $i => $log) :
                            $seq = $total - (($current_page - 1) * $per_page) - $i;
                            $dt  = Guardify_SMS_Logs::to_dhaka(isset($log['timestamp']) ? $log['timestamp'] : '');
                            $raw_msg = isset($log['message']) ? $log['message'] : '';
                            $clean   = trim(strip_tags($raw_msg));
                            if ($clean !== '') {
                                $preview = mb_strlen($clean, 'UTF-8') > 55
                                    ? mb_substr($clean, 0, 55, 'UTF-8') . '…'
                                    : $clean;
                            } else {
                                $preview = '[মেসেজ নেই]';
                            }

                            $status = strtolower(isset($log['status']) ? $log['status'] : 'unknown');
                            $badge_cls = 'gf-badge-muted';
                            if ($status === 'sent' || $status === 'success' || $status === 'delivered') {
                                $badge_cls = 'gf-badge-success';
                            } elseif ($status === 'failed' || $status === 'error') {
                                $badge_cls = 'gf-badge-danger';
                            } elseif ($status === 'pending' || $status === 'queued') {
                                $badge_cls = 'gf-badge-warning';
                            }
                        ?>
                        <tr class="gf-sms-row">
                            <td class="gf-text-muted"><?php echo esc_html(number_format_i18n($seq)); ?></td>
                            <td><strong class="gf-mono"><?php echo esc_html(isset($log['phone_number']) ? $log['phone_number'] : '—'); ?></strong></td>
                            <td>
                                <span class="gf-sms-preview" title="<?php echo esc_attr($clean !== '' ? $clean : 'N/A'); ?>"><?php echo esc_html($preview); ?></span>
                                <?php if (!empty($raw_msg)) : ?>
                                    <button type="button" class="gf-sms-view-btn" data-msg="<?php echo esc_attr($raw_msg); ?>">দেখুন</button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="gf-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                            </td>
                            <td class="gf-table-nowrap">
                                <?php if ($dt instanceof DateTime) : ?>
                                    <?php echo esc_html($dt->format('M j, Y g:i A')); ?>
                                    <br />
                                    <small class="gf-text-muted"><?php echo esc_html(human_time_diff($dt->getTimestamp()) . ' আগে'); ?></small>
                                <?php else : ?>
                                    <?php echo esc_html(isset($log['timestamp']) ? $log['timestamp'] : '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td class="gf-table-center">
                                <span class="gf-badge gf-badge-secondary"><?php echo esc_html(isset($log['sms_parts']) ? $log['sms_parts'] : '1'); ?></span>
                            </td>
                            <td class="gf-table-num gf-text-strong">
                                ৳<?php echo esc_html(isset($log['cost']) ? $log['cost'] : '0'); ?>
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
        $sms_base = admin_url('admin.php?page=guardify-sms-logs');
        if ($current_page > 1) :
            echo '<a href="' . esc_url($sms_base . '&gf_page=' . ($current_page - 1)) . '" aria-label="আগের পেজ">&laquo;</a>';
        else :
            echo '<span class="gf-page-disabled">&laquo;</span>';
        endif;
        for ($i = 1; $i <= $total_pages; $i++) :
            if ($i === $current_page) :
                echo '<span class="gf-page-current" aria-current="page">' . esc_html(number_format_i18n($i)) . '</span>';
            elseif ($i <= 2 || $i > $total_pages - 2 || abs($i - $current_page) <= 1) :
                echo '<a href="' . esc_url($sms_base . '&gf_page=' . $i) . '">' . esc_html(number_format_i18n($i)) . '</a>';
            elseif ($i === 3 || $i === $total_pages - 2) :
                echo '<span class="gf-page-dots">…</span>';
            endif;
        endfor;
        if ($current_page < $total_pages) :
            echo '<a href="' . esc_url($sms_base . '&gf_page=' . ($current_page + 1)) . '" aria-label="পরের পেজ">&raquo;</a>';
        else :
            echo '<span class="gf-page-disabled">&raquo;</span>';
        endif;
        ?>
    </nav>
    <?php endif; ?>

    <div id="gf-sms-modal" class="gf-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="gf-sms-modal-title" style="display:none;">
        <div class="gf-modal">
            <div class="gf-modal-header">
                <h2 class="gf-modal-title" id="gf-sms-modal-title">সম্পূর্ণ SMS</h2>
                <button type="button" class="gf-modal-close" id="gf-sms-modal-close" data-gf-close aria-label="বন্ধ করুন">&times;</button>
            </div>
            <div class="gf-modal-body">
                <pre id="gf-sms-modal-body" class="gf-text-sm" style="white-space:pre-wrap;word-wrap:break-word;margin:0;font-family:inherit;line-height:1.65;"></pre>
            </div>
            <div class="gf-modal-footer">
                <button type="button" class="gf-btn gf-btn-secondary gf-sms-modal-close-btn" data-gf-close>বন্ধ করুন</button>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function ($) {
    /* Search filters the rows already on the page rather than re-querying the
       engine — one page is twenty rows, and a round trip per keystroke would
       spend the merchant's connection for no gain. */
    $('#gf-sms-search').on('input', function () {
        var q = $(this).val().toLowerCase();
        var visible = 0;
        $('#gf-sms-table tbody .gf-sms-row').each(function () {
            var match = !q || $(this).text().toLowerCase().indexOf(q) > -1;
            $(this).toggle(match);
            if (match) { visible++; }
        });
        $('#gf-sms-no-results').toggle(visible === 0 && q.length > 0);
    });

    $(document).on('click', '.gf-sms-view-btn', function () {
        var msg = $(this).data('msg') || '';
        // The stored message may contain markup from a template; render it as
        // the text the customer actually received.
        $('#gf-sms-modal-body').text($('<div/>').html(msg).text());
        window.Guardify.openModal('#gf-sms-modal');
    });
});
</script>
