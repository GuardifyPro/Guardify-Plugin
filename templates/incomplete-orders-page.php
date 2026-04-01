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
                <form method="get" style="display:flex;gap:0.375rem;align-items:center;">
                    <input type="hidden" name="page" value="guardify-incomplete" />
                    <input type="text" name="s" value="<?php echo esc_attr($search); ?>" class="gf-input" placeholder="ফোন বা নাম খুঁজুন..." style="width:200px;height:34px;font-size:0.8125rem;" />
                    <button type="submit" class="gf-btn gf-btn-secondary" style="height:34px;font-size:0.8125rem;padding:0 12px;">সার্চ</button>
                </form>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-ajax.php?action=guardify_export_incomplete'), 'guardify_export_nonce', 'nonce')); ?>" class="gf-btn gf-btn-secondary" style="height:34px;display:inline-flex;align-items:center;gap:4px;font-size:0.8125rem;padding:0 12px;">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    CSV এক্সপোর্ট
                </a>
            </div>
        </div>

        <!-- Bulk actions bar (hidden by default) -->
        <div id="gf-io-bulk-bar" style="display:none;padding:0.625rem 1.25rem;background:var(--gf-muted);border-bottom:1px solid var(--gf-border);display:none;align-items:center;gap:0.5rem;flex-wrap:wrap;">
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
            <div style="padding:3rem 1rem;text-align:center;">
                <p style="font-size:2.5rem;margin:0;">✅</p>
                <p style="font-weight:500;color:var(--gf-fg);margin:0.75rem 0 0.25rem;">কোনো ইনকমপ্লিট অর্ডার নেই</p>
                <p class="gf-text-muted" style="font-size:0.8125rem;">
                    <?php echo $search ? 'সার্চের সাথে কোনো ফলাফল মেলেনি।' : 'সব ভালো! এই মুহূর্তে কোনো অসম্পন্ন অর্ডার নেই।'; ?>
                </p>
            </div>
            <?php else : ?>
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
                        <td>
                            <div style="display:flex;gap:0.375rem;">
                                <button type="button" class="gf-icon-btn gf-icon-btn-success gf-io-sms" data-id="<?php echo esc_attr($row->id); ?>" data-phone="<?php echo esc_attr($row->phone); ?>" data-name="<?php echo esc_attr($row->name); ?>" title="SMS পাঠান">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                </button>
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
            <?php endif; ?>
        </div>
    </div>

    <?php if ($total_pages > 1) : ?>
    <div class="gf-pagination">
        <span><?php echo esc_html($total_count); ?> টি রেকর্ড</span>
        <div>
            <?php
            $base_url = admin_url('admin.php?page=guardify-incomplete');
            if ($search) $base_url .= '&s=' . urlencode($search);
            $page_links = paginate_links([
                'base'      => $base_url . '%_%',
                'format'    => '&gf_page=%#%',
                'current'   => $current_page,
                'total'     => $total_pages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
            ]);
            if ($page_links) echo wp_kses_post($page_links);
            ?>
        </div>
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
            <label class="gf-label">ফোন নম্বর</label>
            <input type="text" id="gf-sms-phone" class="gf-input" readonly style="margin-bottom:0.75rem;" />
            <label class="gf-label">মেসেজ</label>
            <textarea id="gf-sms-message" class="gf-input" rows="5" style="font-size:0.8125rem;">আসসালামু আলাইকুম {customer_name},
আপনি <?php echo esc_js(wp_parse_url(get_site_url(), PHP_URL_HOST)); ?> থেকে অর্ডার সম্পন্ন করেননি। আপনার কার্টে পণ্য অপেক্ষা করছে!
এখনই অর্ডার করুন: <?php echo esc_url(get_permalink(wc_get_page_id('checkout'))); ?>
ধন্যবাদ</textarea>
        </div>
        <div class="gf-modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" style="font-size:0.8125rem;">বাতিল</button>
            <button type="button" id="gf-sms-send" class="gf-btn gf-btn-primary" style="font-size:0.8125rem;">পাঠান</button>
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
        </div>
        <div class="gf-modal-footer" style="display:flex;gap:0.5rem;justify-content:flex-end;">
            <button type="button" class="gf-btn gf-btn-secondary gf-io-modal-close" style="font-size:0.8125rem;">বাতিল</button>
            <button type="button" id="gf-convert-submit" class="gf-btn gf-btn-primary" style="font-size:0.8125rem;">তৈরি করুন</button>
        </div>
    </div>
</div>

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

    // --- SMS ---
    var smsTarget = null; // {phone, name} or 'bulk'

    $(document).on('click', '.gf-io-sms', function(){
        var $row = $(this).closest('tr');
        smsTarget = { phone: $(this).data('phone'), name: $(this).data('name') || '' };
        $('#gf-sms-phone').val(smsTarget.phone);
        openModal('#gf-io-sms-modal');
    });

    $('#gf-io-bulk-sms').on('click', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        // For bulk SMS, send to each selected row
        smsTarget = 'bulk';
        $('#gf-sms-phone').val(ids.length + ' টি নম্বরে পাঠানো হবে');
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
                        $btn.prop('disabled', false).text('পাঠান');
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
                $btn.prop('disabled', false).text('পাঠান');
                alert(r.success ? 'SMS পাঠানো হয়েছে!' : (r.data || 'ব্যর্থ'));
                closeModals();
            });
        }
    });

    // --- Convert ---
    $(document).on('click', '.gf-io-convert', function(){
        $('#gf-convert-id').val($(this).data('id'));
        $('#gf-convert-mode').val('single');
        openModal('#gf-io-convert-modal');
    });

    $('#gf-io-bulk-convert').on('click', function(){
        var ids = getSelectedIds();
        if (!ids.length) return;
        $('#gf-convert-id').val(ids.join(','));
        $('#gf-convert-mode').val('bulk');
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
                $btn.prop('disabled', false).text('তৈরি করুন');
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
                $btn.prop('disabled', false).text('তৈরি করুন');
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
