<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone Search — Admin page for searching phone delivery history.
 */
class Guardify_Search {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_guardify_search_phone', [$this, 'ajax_search_phone']);
    }

    /**
     * Render the phone search admin page.
     */
    public function render_search_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Unauthorized', 'guardify-pro'));
        }

        $nonce = wp_create_nonce('guardify_search_nonce');
        ?>
        <div class="gf-wrap">
            <div class="gf-header">
                <div class="gf-header-left">
                    <div class="gf-logo">G</div>
                    <div>
                        <h1 class="gf-page-title">ফোন সার্চ</h1>
                        <p class="gf-page-desc">কাস্টমারের ডেলিভারি হিস্ট্রি ও ঝুঁকি বিশ্লেষণ</p>
                    </div>
                </div>
            </div>

            <!-- Search Form -->
            <div class="gf-card" style="margin-bottom:1.5rem;">
                <div class="gf-card-body">
                    <form id="gf-search-form" style="display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap;">
                        <div class="gf-form-group" style="flex:1; min-width:0;">
                            <label class="gf-label">ফোন নম্বর</label>
                            <input type="tel" id="gf-search-phone" class="gf-input" placeholder="01XXXXXXXXX" maxlength="11" required />
                        </div>
                        <button type="submit" class="gf-btn gf-btn-primary" id="gf-search-btn" style="height:44px; white-space:nowrap;">
                            সার্চ করুন
                        </button>
                    </form>
                    <div id="gf-search-msg" style="display:none; margin-top:1rem;"></div>
                </div>
            </div>

            <!-- Results -->
            <div id="gf-search-results" style="display:none;">

                <!-- Summary Cards -->
                <div class="gf-stats-grid" style="margin-bottom:1.5rem;">
                    <div class="gf-stat-card">
                        <div class="gf-stat-icon gf-stat-icon-primary">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                        <div>
                            <p class="gf-stat-label">মোট পার্সেল</p>
                            <p class="gf-stat-value" id="gf-r-total">0</p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-icon gf-stat-icon-success">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="gf-stat-label">ডেলিভার্ড</p>
                            <p class="gf-stat-value" id="gf-r-delivered" style="color:var(--gf-success);">0</p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-icon gf-stat-icon-danger">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div>
                            <p class="gf-stat-label">ক্যান্সেল/রিটার্ন</p>
                            <p class="gf-stat-value" id="gf-r-failed" style="color:var(--gf-destructive);">0</p>
                        </div>
                    </div>
                    <div class="gf-stat-card">
                        <div class="gf-stat-icon" id="gf-r-dp-icon">
                            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                        </div>
                        <div>
                            <p class="gf-stat-label">DP রেশিও</p>
                            <p class="gf-stat-value" id="gf-r-dp">0%</p>
                        </div>
                    </div>
                </div>

                <!-- Risk Badge + Progress Bar -->
                <div class="gf-card" style="margin-bottom:1.5rem;">
                    <div class="gf-card-body" style="text-align:center;">
                        <p class="gf-stat-label">ঝুঁকির মাত্রা</p>
                        <span id="gf-r-risk-badge" class="gf-badge" style="font-size:1rem; padding:0.5rem 1.25rem; margin:0.5rem 0;">—</span>
                        <div style="max-width:400px; margin:0.75rem auto 0; height:8px; border-radius:4px; background:var(--gf-border);">
                            <div id="gf-r-dp-bar" style="height:100%; border-radius:4px; transition:width 0.4s;"></div>
                        </div>
                    </div>
                </div>

                <!-- Courier Breakdown Table -->
                <div class="gf-card" id="gf-r-courier-table" style="display:none;">
                    <div class="gf-card-header">
                        <h2 class="gf-card-title">কুরিয়ার ব্রেকডাউন</h2>
                    </div>
                    <div class="gf-card-body" style="padding:0;">
                        <div class="gf-table-wrap">
                        <table class="gf-table">
                            <thead>
                                <tr>
                                    <th>কুরিয়ার</th>
                                    <th>মোট</th>
                                    <th>ডেলিভার্ড</th>
                                    <th>ক্যান্সেল্ড</th>
                                    <th>রিটার্ন্ড</th>
                                    <th>সাকসেস</th>
                                </tr>
                            </thead>
                            <tbody id="gf-r-courier-rows"></tbody>
                        </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var nonce = '<?php echo esc_js($nonce); ?>';

            // Auto-search when 11 digits entered
            $('#gf-search-phone').on('input', function() {
                var phone = $(this).val().replace(/\D/g, '');
                if (/^01\d{9}$/.test(phone)) {
                    $('#gf-search-form').trigger('submit');
                }
            });

            $('#gf-search-form').on('submit', function(e) {
                e.preventDefault();
                var phone = $('#gf-search-phone').val().trim();
                if (!/^01[3-9]\\d{8}$/.test(phone)) {
                    showMsg('error', 'সঠিক ফোন নম্বর দিন (01XXXXXXXXX)');
                    return;
                }

                var $btn = $('#gf-search-btn');
                $btn.prop('disabled', true).text('সার্চ হচ্ছে...');
                $('#gf-search-msg').hide();
                $('#gf-search-results').hide();

                $.post(ajaxurl, {
                    action: 'guardify_search_phone',
                    _wpnonce: nonce,
                    phone: phone
                }, function(res) {
                    $btn.prop('disabled', false).text('সার্চ করুন');
                    if (res.success && res.data) {
                        renderResults(res.data);
                    } else {
                        showMsg('error', res.data || 'সার্চ ব্যর্থ হয়েছে।');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('সার্চ করুন');
                    showMsg('error', 'সার্ভারে সংযোগ করা যায়নি।');
                });
            });

            function renderResults(d) {
                $('#gf-r-total').text(d.total_parcels || 0);
                $('#gf-r-delivered').text(d.total_delivered || 0);
                $('#gf-r-failed').text((d.total_cancelled || 0) + (d.total_returned || 0));

                var dp = (d.dp_ratio || 0);
                var dpColor = dp >= 80 ? 'var(--gf-success)' : (dp >= 50 ? 'var(--gf-warning)' : 'var(--gf-destructive)');
                $('#gf-r-dp').text(dp.toFixed(1) + '%').css('color', dpColor);
                $('#gf-r-dp-icon').css('background', dpColor.replace(')', ', 0.1)').replace('var(', 'color-mix(in oklch, ')).css('color', dpColor);
                $('#gf-r-dp-bar').css({ width: Math.min(dp, 100) + '%', background: dpColor });

                var riskBn = { low: 'নিম্ন ঝুঁকি', medium: 'মাঝারি ঝুঁকি', high: 'উচ্চ ঝুঁকি' };
                var riskCls = { low: 'gf-badge-success', medium: 'gf-badge-warning', high: 'gf-badge-danger' };
                var risk = d.risk_level || 'high';
                $('#gf-r-risk-badge').text(riskBn[risk] || risk).attr('class', 'gf-badge ' + (riskCls[risk] || 'gf-badge-danger'));

                // Courier table
                var $tbody = $('#gf-r-courier-rows').empty();
                if (d.providers && d.providers.length > 0) {
                    d.providers.forEach(function(p, idx) {
                        var sr = p.total_parcels > 0 ? ((p.total_delivered / p.total_parcels) * 100).toFixed(1) + '%' : '—';
                        var hasDetails = p.details && p.details.length > 0;
                        var toggleId = 'gf-detail-' + idx;
                        var nameHtml = hasDetails
                            ? '<button type="button" class="gf-expand-btn" data-target="' + toggleId + '" style="background:none;border:none;cursor:pointer;padding:0;font:inherit;color:var(--gf-primary);display:inline-flex;align-items:center;gap:4px;">' +
                              '<span class="gf-expand-arrow" style="display:inline-block;transition:transform .2s;">&#9654;</span> ' + esc(p.provider) + '</button>'
                            : '<strong>' + esc(p.provider) + '</strong>';
                        $tbody.append(
                            '<tr>' +
                            '<td>' + nameHtml + '</td>' +
                            '<td>' + p.total_parcels + '</td>' +
                            '<td style="color:var(--gf-success);">' + p.total_delivered + '</td>' +
                            '<td>' + (p.total_cancelled || 0) + '</td>' +
                            '<td>' + (p.total_returned || 0) + '</td>' +
                            '<td><strong>' + sr + '</strong></td>' +
                            '</tr>'
                        );
                        if (hasDetails) {
                            var detailRows = '<tr id="' + toggleId + '" style="display:none;"><td colspan="6" style="padding:0;">' +
                                '<table class="gf-table" style="background:var(--gf-muted);margin:0;"><thead><tr>' +
                                '<th>ট্র্যাকিং</th><th>স্ট্যাটাস</th><th>COD</th><th>তারিখ</th>' +
                                '</tr></thead><tbody>';
                            p.details.forEach(function(det, di) {
                                var statusCls = det.status === 'DELIVERED' ? 'gf-badge-success' : (det.status === 'CANCELLED' || det.status === 'RETURNED' ? 'gf-badge-danger' : 'gf-badge-secondary');
                                detailRows += '<tr>' +
                                    '<td style="font-family:monospace;">' + esc(det.tracking_id || '—') + '</td>' +
                                    '<td><span class="gf-badge ' + statusCls + '">' + esc(det.status || '—') + '</span></td>' +
                                    '<td>' + (det.cod_amount ? '৳' + det.cod_amount : '—') + '</td>' +
                                    '<td>' + (det.created_at ? new Date(det.created_at).toLocaleDateString('bn-BD') : '—') + '</td>' +
                                    '</tr>';
                            });
                            detailRows += '</tbody></table></td></tr>';
                            $tbody.append(detailRows);
                        }
                    });
                    // Toggle expand/collapse (use off+on to prevent duplicate bindings)
                    $tbody.off('click', '.gf-expand-btn').on('click', '.gf-expand-btn', function() {
                        var target = $(this).data('target');
                        var $row = $('#' + target);
                        var $arrow = $(this).find('.gf-expand-arrow');
                        $row.toggle();
                        $arrow.css('transform', $row.is(':visible') ? 'rotate(90deg)' : 'rotate(0deg)');
                    });
                    $('#gf-r-courier-table').show();
                } else {
                    $('#gf-r-courier-table').hide();
                }

                $('#gf-search-results').show();
            }

            function showMsg(type, text) {
                var cls = type === 'success' ? 'gf-alert-success' : 'gf-alert-error';
                $('#gf-search-msg').html('<div class="gf-alert ' + cls + '">' + esc(text) + '</div>').show();
            }

            function esc(s) { return $('<span>').text(s).html(); }
        });
        </script>
        <?php
    }

    /**
     * AJAX: Search phone delivery history.
     */
    public function ajax_search_phone() {
        check_ajax_referer('guardify_search_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        if (empty($phone) || !preg_match('/^01[3-9]\d{8}$/', $phone)) {
            wp_send_json_error('সঠিক ফোন নম্বর দিন (01XXXXXXXXX)');
        }

        $api = new Guardify_API();
        $result = $api->get('/api/v1/courier/history', ['phone' => $phone]);

        if (!empty($result['success']) && isset($result['data'])) {
            wp_send_json_success($result['data']);
        } elseif (isset($result['phone'])) {
            wp_send_json_success($result);
        }

        $error = isset($result['error']) ? $result['error'] : 'সার্চ ব্যর্থ।';
        wp_send_json_error($error);
    }
}
