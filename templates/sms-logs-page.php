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
    <h1 class="gf-page-title">SMS লগস</h1>
    <p class="gf-subtitle">পাঠানো সকল SMS-এর বিস্তারিত তালিকা ও খরচ।</p>

    <?php if ($error) : ?>
        <div class="notice notice-error" style="margin: 16px 0;">
            <p><strong>ত্রুটি:</strong> <?php echo esc_html($error); ?></p>
        </div>
    <?php endif; ?>

    <div class="gf-card" style="margin-top: 16px;">
        <div class="gf-card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <h2 class="gf-card-title" style="display: flex; align-items: center; gap: 8px;">
                SMS লগস
                <?php if ($total > 0) : ?>
                    <span class="gf-sms-count"><?php echo esc_html($total); ?></span>
                <?php endif; ?>
            </h2>
            <div style="display: flex; gap: 8px; align-items: center;">
                <input type="text" id="gf-sms-search" class="gf-input" placeholder="ফোন বা মেসেজ সার্চ করুন..." style="width: 240px; height: 34px; font-size: 0.8125rem;" />
                <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-sms-logs')); ?>" class="gf-btn gf-btn-secondary" style="height: 34px; display: inline-flex; align-items: center; gap: 4px; font-size: 0.8125rem; padding: 0 12px;">
                    🔄 রিফ্রেশ
                </a>
            </div>
        </div>
        <div class="gf-card-body" style="padding: 0;">
            <?php if (empty($logs) && empty($error)) : ?>
                <div class="gf-empty-state" style="padding: 3rem 1rem; text-align: center;">
                    <p style="font-size: 2.5rem; margin: 0;">📩</p>
                    <p style="font-weight: 500; color: var(--gf-text, #1f2937); margin: 0.75rem 0 0.25rem;">কোনো SMS লগ পাওয়া যায়নি</p>
                    <p class="gf-text-muted" style="font-size: 0.8125rem;">এখনো কোনো SMS পাঠানো হয়নি, অথবা API থেকে ডেটা আনতে সমস্যা হচ্ছে।</p>
                </div>
            <?php else : ?>
                <div id="gf-sms-no-results" style="display: none; padding: 2rem 1rem; text-align: center;">
                    <p style="font-weight: 500; color: var(--gf-text, #1f2937);">কোনো ফলাফল পাওয়া যায়নি</p>
                    <p class="gf-text-muted" style="font-size: 0.8125rem;">আপনার সার্চ মানদণ্ডের সাথে কোনো SMS মেলেনি।</p>
                </div>
                <table class="wp-list-table widefat fixed striped gf-sms-table" id="gf-sms-table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">#</th>
                            <th style="width: 140px;">ফোন</th>
                            <th>মেসেজ</th>
                            <th style="width: 90px;">স্ট্যাটাস</th>
                            <th style="width: 170px;">সময়</th>
                            <th style="width: 60px; text-align: center;">পার্টস</th>
                            <th style="width: 80px; text-align: right;">খরচ</th>
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
                                    <span class="gf-badge <?php echo esc_attr($badge_cls); ?>" style="font-size: 0.75rem;"><?php echo esc_html(ucfirst($status)); ?></span>
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
                                <td style="text-align: center;">
                                    <span class="gf-badge gf-badge-muted" style="font-size: 0.75rem;"><?php echo esc_html($log['sms_parts'] ?? '1'); ?></span>
                                </td>
                                <td style="text-align: right; font-weight: 500;">
                                    ৳<?php echo esc_html($log['cost'] ?? '0'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($total_pages > 1) : ?>
    <div class="tablenav bottom" style="margin-top: 12px;">
        <div class="tablenav-pages">
            <span class="displaying-num"><?php echo esc_html($total); ?> টি রেকর্ড</span>
            <?php
            $page_links = paginate_links([
                'base'      => admin_url('admin.php?page=guardify-sms-logs') . '%_%',
                'format'    => '&gf_page=%#%',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            if ($page_links) {
                echo wp_kses_post($page_links);
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Full Message Modal -->
<div id="gf-sms-modal" style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,0.5); align-items:center; justify-content:center;">
    <div style="background:#fff; border-radius:12px; max-width:520px; width:90%; max-height:80vh; overflow:auto; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid #e5e7eb;">
            <h3 style="margin:0; font-size:1rem; font-weight:600;">📱 সম্পূর্ণ SMS</h3>
            <button type="button" id="gf-sms-modal-close" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#6b7280; line-height:1;">&times;</button>
        </div>
        <div style="padding:20px;">
            <pre id="gf-sms-modal-body" style="white-space:pre-wrap; word-wrap:break-word; font-size:0.875rem; line-height:1.6; margin:0; font-family:inherit; color:#374151;"></pre>
        </div>
        <div style="padding:12px 20px; border-top:1px solid #e5e7eb; text-align:right; background:#f9fafb; border-radius:0 0 12px 12px;">
            <button type="button" class="gf-btn gf-btn-secondary gf-sms-modal-close-btn" style="font-size:0.8125rem;">বন্ধ করুন</button>
        </div>
    </div>
</div>

<style>
.gf-sms-count {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 24px; height: 22px; padding: 0 8px;
    border-radius: 11px; background: var(--gf-primary, #3b82f6); color: #fff;
    font-size: 0.75rem; font-weight: 500;
}
.gf-sms-table th {
    font-size: 0.8125rem; font-weight: 500; color: #6b7280;
    padding: 10px 14px; border-bottom: 1px solid #e5e7eb; background: #f9fafb;
}
.gf-sms-table td {
    padding: 10px 14px; font-size: 0.8125rem; vertical-align: middle;
    border-bottom: 1px solid #f3f4f6; color: #374151;
}
.gf-sms-table tr:hover td { background: #f9fafb; }
.gf-sms-preview {
    display: inline-block; max-width: 220px; overflow: hidden;
    text-overflow: ellipsis; white-space: nowrap; vertical-align: middle;
}
.gf-sms-view-btn {
    background: none; border: none; color: var(--gf-primary, #3b82f6);
    font-size: 0.75rem; font-weight: 500; cursor: pointer; padding: 0; margin-left: 6px;
    text-decoration: underline;
}
.gf-sms-view-btn:hover { color: #2563eb; }
.gf-badge-muted { background: #f3f4f6; color: #6b7280; }
.gf-badge-warning { background: #fef3c7; color: #92400e; }
</style>

<script>
jQuery(function($){
    // Search filter
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

    // View full message modal
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
