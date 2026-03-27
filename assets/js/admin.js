/**
 * Guardify Pro — Admin JavaScript
 */
(function ($) {
    'use strict';

    var data = window.guardifyData || {};

    /* ── Connect Form ─────────────────────────────────────────────────── */

    $(document).on('submit', '#gf-connect-form', function (e) {
        e.preventDefault();

        var apiKey    = $('#gf-api-key').val().trim();
        var secretKey = $('#gf-secret-key').val().trim();

        if (!apiKey || !secretKey) return;

        var $btn = $('#gf-connect-btn');
        $btn.prop('disabled', true).text('যাচাই হচ্ছে...');
        hideMsg();

        $.post(data.ajaxUrl, {
            action:     'guardify_connect',
            _wpnonce:   data.nonce,
            api_key:    apiKey,
            secret_key: secretKey
        })
        .done(function (res) {
            if (res.success) {
                showMsg('success', 'সফলভাবে সংযুক্ত হয়েছে। পেজ রিলোড হচ্ছে...');
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showMsg('error', res.data || 'সংযোগ ব্যর্থ হয়েছে।');
                $btn.prop('disabled', false).text('সংযুক্ত করুন');
            }
        })
        .fail(function () {
            showMsg('error', 'সার্ভারে সংযোগ করা যায়নি।');
            $btn.prop('disabled', false).text('সংযুক্ত করুন');
        });
    });

    /* ── Disconnect ───────────────────────────────────────────────────── */

    $(document).on('click', '#gf-disconnect-btn', function () {
        if (!confirm('আপনি কি নিশ্চিত? সংযোগ বিচ্ছিন্ন করলে Guardify Pro নিষ্ক্রিয় হবে।')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('বিচ্ছিন্ন হচ্ছে...');

        $.post(data.ajaxUrl, {
            action:   'guardify_disconnect',
            _wpnonce: data.nonce
        })
        .done(function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data || 'বিচ্ছিন্ন করা যায়নি।');
                $btn.prop('disabled', false).text('সংযোগ বিচ্ছিন্ন করুন');
            }
        })
        .fail(function () {
            alert('সার্ভারে সংযোগ করা যায়নি।');
            $btn.prop('disabled', false).text('সংযোগ বিচ্ছিন্ন করুন');
        });
    });

    /* ── Auto-check status on load ────────────────────────────────────── */

    if (data.connected) {
        $.post(data.ajaxUrl, {
            action:   'guardify_status',
            _wpnonce: data.nonce
        })
        .done(function (res) {
            if (res.success && res.data) {
                $('#gf-status-text').text(res.data.active ? 'সক্রিয়' : 'নিষ্ক্রিয়');
                if (res.data.plan) {
                    $('#gf-plan-text').text(res.data.plan);
                }
            } else {
                $('#gf-status-text').text('যাচাই ব্যর্থ');
            }
        })
        .fail(function () {
            $('#gf-status-text').text('সংযোগ ত্রুটি');
        });
    }

    /* ── Helpers ───────────────────────────────────────────────────────── */

    function showMsg(type, text) {
        var cls = type === 'success' ? 'gf-alert-success' : 'gf-alert-error';
        $('#gf-connect-msg').html('<div class="gf-alert ' + cls + '">' + escHtml(text) + '</div>').show();
    }

    function hideMsg() {
        $('#gf-connect-msg').hide().empty();
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
