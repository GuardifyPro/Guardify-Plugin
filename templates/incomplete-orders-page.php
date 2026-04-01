<?php defined('ABSPATH') || exit; ?>
<div class="wrap gf-wrap">
    <div class="gf-header">
        <div class="gf-header-left">
            <div class="gf-logo">📋</div>
            <div>
                <h1 class="gf-page-title">ইনকমপ্লিট অর্ডার</h1>
                <p class="gf-text-muted" style="margin: 0.25rem 0 0; font-size: 0.8125rem;">চেকআউটে আসা কিন্তু অর্ডার সম্পন্ন না করা গ্রাহকদের তালিকা।</p>
            </div>
        </div>
    </div>

    <?php
    $per_page = 20;
    $current_page = isset($_GET['gf_page']) ? max(1, absint($_GET['gf_page'])) : 1;
    $offset = ($current_page - 1) * $per_page;
    $total_count = Guardify_Incomplete_Orders::get_pending_count();
    $orders = Guardify_Incomplete_Orders::get_pending($per_page, $offset);
    $total_pages = max(1, (int) ceil($total_count / $per_page));

    if (empty($orders)) {
        echo '<div class="gf-card"><div class="gf-card-body"><div class="gf-empty-state" style="padding:2rem 1rem;text-align:center;">';
        echo '<p style="font-size:2.5rem;margin:0;">✅</p>';
        echo '<p style="font-weight:500;color:var(--gf-fg);margin:0.75rem 0 0.25rem;">কোনো ইনকমপ্লিট অর্ডার নেই</p>';
        echo '<p class="gf-text-muted" style="font-size:0.8125rem;">সব ভালো! এই মুহূর্তে কোনো অসম্পন্ন অর্ডার নেই।</p>';
        echo '</div></div></div>';
        return;
    }
    ?>

    <div class="gf-card">
        <div class="gf-card-header" style="display:flex;align-items:center;justify-content:space-between;">
            <h2 class="gf-card-title" style="display:flex;align-items:center;gap:0.5rem;">
                অসম্পন্ন অর্ডার <span class="gf-count-pill"><?php echo esc_html($total_count); ?></span>
            </h2>
        </div>
        <div class="gf-card-body" style="padding:0;">
            <table class="gf-table">
                <thead>
                    <tr>
                        <th>নাম</th>
                        <th>ফোন</th>
                        <th>শহর</th>
                        <th>কার্ট</th>
                        <th>মোট</th>
                        <th>সময়</th>
                        <th class="gf-col-action">অ্যাকশন</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $row) :
                        $cart = json_decode($row->cart_data, true);
                        $cart_summary = '';
                        if (!empty($cart)) {
                            $names = array_column($cart, 'name');
                            $cart_summary = implode(', ', array_slice($names, 0, 2));
                            if (count($names) > 2) {
                                $cart_summary .= ' +' . (count($names) - 2);
                            }
                        }
                    ?>
                        <tr id="gf-io-row-<?php echo esc_attr($row->id); ?>">
                            <td><?php echo esc_html($row->name ?: '—'); ?></td>
                            <td><strong><?php echo esc_html($row->phone); ?></strong></td>
                            <td><?php echo esc_html($row->city ?: '—'); ?></td>
                            <td title="<?php echo esc_attr($cart_summary); ?>"><?php echo esc_html($cart_summary ?: '—'); ?></td>
                            <td style="white-space:nowrap;"><?php echo esc_html($row->cart_total ? '৳' . number_format($row->cart_total) : '—'); ?></td>
                            <td style="white-space:nowrap;"><?php echo esc_html(human_time_diff(strtotime($row->created_at)) . ' আগে'); ?></td>
                            <td>
                                <div style="display:flex;gap:0.375rem;">
                                    <button type="button" class="gf-icon-btn gf-icon-btn-success gf-io-sms" data-id="<?php echo esc_attr($row->id); ?>" title="SMS পাঠান">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                        SMS
                                    </button>
                                    <button type="button" class="gf-icon-btn gf-io-convert" data-id="<?php echo esc_attr($row->id); ?>" title="অর্ডারে কনভার্ট">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 014-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
                                        কনভার্ট
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
    </div>

    <?php if ($total_pages > 1) : ?>
    <div class="gf-pagination">
        <span><?php echo esc_html($total_count); ?> টি রেকর্ড</span>
        <div>
            <?php
            $base_url = admin_url('admin.php?page=guardify-incomplete');
            $page_links = paginate_links(array(
                'base'      => $base_url . '%_%',
                'format'    => '&gf_page=%#%',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ));
            if ($page_links) {
                echo wp_kses_post($page_links);
            }
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($){
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';

    $(document).on('click', '.gf-io-sms', function(){
        var btn = $(this), id = btn.data('id');
        btn.prop('disabled', true).css('opacity', 0.6);
        $.post(ajaxurl, { action: 'guardify_send_recovery_sms', id: id, _ajax_nonce: nonce }, function(r){
            btn.prop('disabled', false).css('opacity', 1);
            alert(r.success ? 'SMS পাঠানো হয়েছে!' : (r.data || 'ব্যর্থ'));
        });
    });

    $(document).on('click', '.gf-io-convert', function(){
        var btn = $(this), id = btn.data('id');
        if (!confirm('এই রেকর্ড থেকে WC অর্ডার তৈরি করতে চান?')) return;
        btn.prop('disabled', true).css('opacity', 0.6);
        $.post(ajaxurl, { action: 'guardify_convert_incomplete', id: id, _ajax_nonce: nonce }, function(r){
            if (r.success) {
                $('#gf-io-row-' + id).fadeOut();
                alert('অর্ডার #' + r.data.order_id + ' তৈরি হয়েছে!');
            } else {
                btn.prop('disabled', false).css('opacity', 1);
                alert(r.data || 'ব্যর্থ');
            }
        });
    });

    $(document).on('click', '.gf-io-delete', function(){
        var btn = $(this), id = btn.data('id');
        if (!confirm('মুছে ফেলতে চান?')) return;
        $.post(ajaxurl, { action: 'guardify_delete_incomplete', id: id, _ajax_nonce: nonce }, function(r){
            if (r.success) $('#gf-io-row-' + id).fadeOut();
        });
    });
});
</script>
