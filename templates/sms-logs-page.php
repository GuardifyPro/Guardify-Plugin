<?php
defined('ABSPATH') || exit;

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
    <div class="gf-header">
        <div class="gf-header-left">
            <div class="gf-logo">💬</div>
            <div>
                <h1 class="gf-page-title">SMS লগস</h1>
                <p class="gf-text-muted" style="margin:0.25rem 0 0;font-size:0.8125rem;">পাঠানো সকল SMS-এর বিস্তারিত তালিকা ও খরচ।</p>
            </div>
        </div>
    </div>

    <?php if ($error) : ?>
        <div class="gf-alert gf-alert-error" style="margin-bottom:1rem;"><?php echo esc_html($error); ?></div>
    <?php endif; ?>

    <div class="gf-card">
        <div class="gf-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
            <h2 class="gf-card-title" style="display:flex;align-items:center;gap:0.5rem;">
                SMS লগস
                <?php if ($total > 0) : ?>
                    <span class="gf-count-pill"><?php echo esc_html($total); ?></span>
                <?php endif; ?>
            </h2>
            <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
                <input type="text" id="gf-sms-search" class="gf-input" placeholder="ফোন বা মেসেজ সার্চ করুন..." style="min-width:0;width:min(100%,240px);height:34px;font-size:0.8125rem;" />
                <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-sms-logs')); ?>" class="gf-btn gf-btn-secondary" style="height:34px;display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;padding:0 12px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                    রিফ্রেশ
                </a>
            </div>
        </div>
        <div class="gf-card-body" style="padding:0;">
            <?php if (empty($logs) && empty($error)) : ?>
                <div class="gf-empty-state">
                    <div class="gf-empty-state-icon">📩</div>
                    <p class="gf-empty-state-title">কোনো SMS লগ পাওয়া যায়নি</p>
                    <p class="gf-empty-state-desc">এখনো কোনো SMS পাঠানো হয়নি, অথবা API থেকে ডেটা আনতে সমস্যা হচ্ছে।</p>
                </div>
            <?php else : ?>
                <div id="gf-sms-no-results" style="display:none;padding:2rem 1rem;text-align:center;">
                    <p style="font-weight:500;color:var(--gf-fg);">কোনো ফলাফল পাওয়া যায়নি</p>
                    <p class="gf-text-muted" style="font-size:0.8125rem;">আপনার সার্চ মানদণ্ডের সাথে কোনো SMS মেলেনি।</p>
                </div>
                <div class="gf-table-wrap">
                <table class="gf-table" id="gf-sms-table">
                    <thead>
                        <tr>
                            <th style="width:60px;">#</th>
                            <th style="width:140px;">ফোন</th>
                            <th>মেসেজ</th>
                            <th style="width:90px;">স্ট্যাটাস</th>
                            <th style="width:170px;">সময়</th>
                            <th style="width:60px;text-align:center;">পার্টস</th>
                            <th style="width:80px;text-align:right;">খরচ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $i => $log) :
                            $seq = $total - (($current_page - 1) * $per_page) - $i;
                            $dt  = Guardify_SMS_Logs::to_dhaka($log['timestamp'] ?? '');
                            $raw_msg = isset($log['message']) ? $log['message'] : '';
                            $clean   = trim(strip_tags($raw_msg));
                            $preview = '';
                            if (!empty($clean)) {
                                $preview = mb_strlen($clean, 'UTF-8') > 55
                                    ? mb_substr($clean, 0, 55, 'UTF-8') . '…'
                                    : $clean;
                            } else {
                                $preview = '[মেসেজ নেই]';
                            }
                        ?>
                            <tr class="gf-sms-row">
                                <td><?php echo esc_html($seq); ?></td>
                                <td><strong><?php echo esc_html($log['phone_number'] ?? '—'); ?></strong></td>
                                <td>
                                    <span class="gf-sms-preview" title="<?php echo esc_attr($clean ?: 'N/A'); ?>"><?php echo esc_html($preview); ?></span>
                                    <?php if (!empty($raw_msg)) : ?>
                                        <button type="button" class="gf-sms-view-btn" data-msg="<?php echo esc_attr($raw_msg); ?>">দেখুন</button>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status = strtolower($log['status'] ?? 'unknown');
                                    $badge_cls = 'gf-badge-muted';
                                    if ($status === 'sent' || $status === 'success' || $status === 'delivered') {
                                        $badge_cls = 'gf-badge-success';
                                    } elseif ($status === 'failed' || $status === 'error') {
                                        $badge_cls = 'gf-badge-danger';
                                    } elseif ($status === 'pending' || $status === 'queued') {
                                        $badge_cls = 'gf-badge-warning';
                                    }
                                    ?>
                                    <span class="gf-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html(ucfirst($status)); ?></span>
                                </td>
                                <td>
                                    <?php
                                    if ($dt instanceof DateTime) {
                                        echo esc_html($dt->format('M j, Y g:i A'));
                                        echo '<br><small class="gf-text-muted">' . esc_html(human_time_diff($dt->getTimestamp()) . ' আগে') . '</small>';
                                    } else {
                                        echo esc_html($log['timestamp'] ?? '—');
                                    }
                                    ?>
                                </td>
                                <td style="text-align:center;">
                                    <span class="gf-badge gf-badge-secondary"><?php echo esc_html($log['sms_parts'] ?? '1'); ?></span>
                                </td>
                                <td style="text-align:right;font-weight:500;">
                                    ৳<?php echo esc_html($log['cost'] ?? '0'); ?>
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
        $sms_base = admin_url('admin.php?page=guardify-sms-logs');
        if ($current_page > 1) :
            echo '<a href="' . esc_url($sms_base . '&gf_page=' . ($current_page - 1)) . '">&laquo;</a>';
        else :
            echo '<span class="gf-page-disabled">&laquo;</span>';
        endif;
        for ($i = 1; $i <= $total_pages; $i++) :
            if ($i === $current_page) :
                echo '<span class="gf-page-current">' . esc_html($i) . '</span>';
            elseif ($i <= 2 || $i > $total_pages - 2 || abs($i - $current_page) <= 1) :
                echo '<a href="' . esc_url($sms_base . '&gf_page=' . $i) . '">' . esc_html($i) . '</a>';
            elseif ($i === 3 || $i === $total_pages - 2) :
                echo '<span class="gf-page-dots">…</span>';
            endif;
        endfor;
        if ($current_page < $total_pages) :
            echo '<a href="' . esc_url($sms_base . '&gf_page=' . ($current_page + 1)) . '">&raquo;</a>';
        else :
            echo '<span class="gf-page-disabled">&raquo;</span>';
        endif;
        ?>
    </div>
    <?php endif; ?>
</div>

<!-- Full Message Modal -->
<div id="gf-sms-modal" class="gf-modal-overlay" style="display:none;">
    <div class="gf-modal" style="max-width:520px;">
        <div class="gf-modal-header">
            <h3 style="margin:0;font-size:1rem;font-weight:600;">💬 সম্পূর্ণ SMS</h3>
            <button type="button" id="gf-sms-modal-close" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:var(--gf-muted-fg);line-height:1;">&times;</button>
        </div>
        <div class="gf-modal-body">
            <pre id="gf-sms-modal-body" style="white-space:pre-wrap;word-wrap:break-word;font-size:0.875rem;line-height:1.6;margin:0;font-family:inherit;color:var(--gf-fg);"></pre>
        </div>
        <div class="gf-modal-footer">
            <button type="button" class="gf-btn gf-btn-secondary gf-sms-modal-close-btn" style="font-size:0.8125rem;">বন্ধ করুন</button>
        </div>
    </div>
</div>

<script>
jQuery(function($){
    $('#gf-sms-search').on('input', function(){
        var q = $(this).val().toLowerCase();
        var visible = 0;
        $('#gf-sms-table tbody .gf-sms-row').each(function(){
            var text = $(this).text().toLowerCase();
            var match = !q || text.indexOf(q) > -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $('#gf-sms-no-results').toggle(visible === 0 && q.length > 0);
    });

    $(document).on('click', '.gf-sms-view-btn', function(){
        var msg = $(this).data('msg') || '';
        var clean = $('<div/>').html(msg).text();
        $('#gf-sms-modal-body').text(clean);
        $('#gf-sms-modal').css('display', 'flex');
    });
    $(document).on('click', '#gf-sms-modal-close, .gf-sms-modal-close-btn, #gf-sms-modal', function(e){
        if (e.target === this) $('#gf-sms-modal').hide();
    });
});
</script>
