<?php defined('ABSPATH') || exit; ?>
<div class="wrap gf-wrap">
    <h1 class="gf-page-title">ইনকমপ্লিট অর্ডার</h1>
    <p class="gf-subtitle">চেকআউটে আসা কিন্তু অর্ডার সম্পন্ন না করা গ্রাহকদের তালিকা।</p>

    <?php
    $orders = Guardify_Incomplete_Orders::get_pending();

    if (empty($orders)) {
        echo '<div class="gf-empty-state"><p>কোনো ইনকমপ্লিট অর্ডার নেই।</p></div>';
        return;
    }
    ?>

    <table class="wp-list-table widefat fixed striped" style="margin-top:16px;">
        <thead>
            <tr>
                <th>নাম</th>
                <th>ফোন</th>
                <th>শহর</th>
                <th>কার্ট</th>
                <th>মোট</th>
                <th>সময়</th>
                <th>অ্যাকশন</th>
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
                    <td><?php echo esc_html($row->cart_total ? '৳' . number_format($row->cart_total) : '—'); ?></td>
                    <td><?php echo esc_html(human_time_diff(strtotime($row->created_at)) . ' আগে'); ?></td>
                    <td>
                        <button type="button" class="button button-small gf-io-sms" data-id="<?php echo esc_attr($row->id); ?>" title="SMS পাঠান">📩 SMS</button>
                        <button type="button" class="button button-small gf-io-convert" data-id="<?php echo esc_attr($row->id); ?>" title="অর্ডারে কনভার্ট">🔄 কনভার্ট</button>
                        <button type="button" class="button button-small gf-io-delete" data-id="<?php echo esc_attr($row->id); ?>" title="মুছুন">🗑️</button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
jQuery(function($){
    var nonce = '<?php echo esc_js(wp_create_nonce('guardify_nonce')); ?>';

    $(document).on('click', '.gf-io-sms', function(){
        var btn = $(this), id = btn.data('id');
        btn.prop('disabled', true).text('পাঠানো হচ্ছে...');
        $.post(ajaxurl, { action: 'guardify_send_recovery_sms', id: id, _ajax_nonce: nonce }, function(r){
            btn.prop('disabled', false).text('📩 SMS');
            alert(r.success ? 'SMS পাঠানো হয়েছে!' : (r.data || 'ব্যর্থ'));
        });
    });

    $(document).on('click', '.gf-io-convert', function(){
        var btn = $(this), id = btn.data('id');
        if (!confirm('এই রেকর্ড থেকে WC অর্ডার তৈরি করতে চান?')) return;
        btn.prop('disabled', true).text('তৈরি হচ্ছে...');
        $.post(ajaxurl, { action: 'guardify_convert_incomplete', id: id, _ajax_nonce: nonce }, function(r){
            if (r.success) {
                $('#gf-io-row-' + id).fadeOut();
                alert('অর্ডার #' + r.data.order_id + ' তৈরি হয়েছে!');
            } else {
                btn.prop('disabled', false).text('🔄 কনভার্ট');
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
