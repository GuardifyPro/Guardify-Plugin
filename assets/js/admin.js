/**
 * Guardify Pro — Admin JavaScript
 */
(function ($) {
    'use strict';

    var data = window.guardifyData || {};

    /* ── Connect Form ─────────────────────────────────────────────────── */

    $(document).on('submit', '#gf-connect-form', function (e) {
        e.preventDefault();

        var apiKey = $('#gf-connection-key').val().trim();

        if (!apiKey || !apiKey.match(/^gp_[a-f0-9]+$/i)) {
            showMsg('error', 'সঠিক API কী দিন। ফরম্যাট: gp_xxxx — guardify.pro/api-keys থেকে কপি করুন।');
            return;
        }

        var $btn = $('#gf-connect-btn');
        $btn.prop('disabled', true).text('যাচাই হচ্ছে...');
        hideMsg();

        $.post(data.ajaxUrl, {
            action:     'guardify_connect',
            _wpnonce:   data.nonce,
            api_key:    apiKey
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data || {};
                var planMap = { free: 'Free', starter: 'Starter', business: 'Business' };
                var planName = planMap[d.plan] || d.plan || 'Free';
                var sms = d.sms_balance !== undefined ? d.sms_balance : 0;
                var info = '✅ সফলভাবে সংযুক্ত হয়েছে!\n\n';
                info += '📦 প্ল্যান: ' + planName + '\n';
                info += '💬 SMS ব্যালেন্স: ' + sms + '\n';
                if (d.expires_at) {
                    var exp = new Date(d.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    info += '📅 মেয়াদ: ' + (days > 0 ? days + ' দিন বাকি' : 'মেয়াদ শেষ');
                }
                showMsg('success', info.replace(/\n/g, '<br>'));
                setTimeout(function () { location.reload(); }, 2000);
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
                    var planMap = { free: 'Free', starter: 'Starter', business: 'Business' };
                    $('#gf-plan-text').text(planMap[res.data.plan] || res.data.plan);
                }
                if (res.data.sms_balance !== undefined) {
                    $('#gf-sms-text').text(res.data.sms_balance.toLocaleString('bn-BD'));
                }
                if (res.data.expires_at) {
                    var exp = new Date(res.data.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    var daysText = days > 0 ? days + ' দিন বাকি' : 'মেয়াদ শেষ';
                    $('#gf-plan-text').append(' <small style="opacity:0.7">(' + daysText + ')</small>');
                }
            } else {
                $('#gf-status-text').text('যাচাই ব্যর্থ');
            }
        })
        .fail(function () {
            $('#gf-status-text').text('সংযোগ ত্রুটি');
        });
    }

    /* ── Tab Navigation ───────────────────────────────────────────────── */

    $(document).on('click', '.gf-tab', function () {
        var tab = $(this).data('tab');
        $('.gf-tab').removeClass('active');
        $(this).addClass('active');
        $('.gf-tab-content').hide();
        $('#gf-tab-' + tab).show();
    });

    /* ── Save Settings ────────────────────────────────────────────────── */

    $(document).on('click', '#gf-save-settings', function () {
        var $btn = $(this);
        var $msg = $('#gf-save-msg');
        $btn.prop('disabled', true).text('সংরক্ষণ হচ্ছে...');
        $msg.hide();

        var payload = {
            action:   'guardify_save_settings',
            _wpnonce: data.nonce
        };

        // Collect all toggle checkboxes
        $('.gf-setting-toggle').each(function () {
            var name = $(this).attr('name');
            if (!name) return;
            // Handle array names (e.g., guardify_notification_statuses[])
            if (name.indexOf('[]') !== -1) {
                var baseName = name.replace('[]', '');
                if (!payload[baseName]) payload[baseName] = [];
                if ($(this).is(':checked')) {
                    payload[baseName].push($(this).val());
                }
            } else {
                payload[name] = $(this).is(':checked') ? 'yes' : 'no';
            }
        });

        // Collect text/number/select inputs
        $('.gf-setting-input').each(function () {
            var name = $(this).attr('name');
            if (!name) return;
            payload[name] = $(this).val();
        });

        $.post(data.ajaxUrl, payload)
        .done(function (res) {
            if (res.success) {
                $msg.text('✓ সংরক্ষিত').css('color', 'var(--gf-success)').show();
            } else {
                $msg.text('✕ সংরক্ষণ ব্যর্থ').css('color', 'var(--gf-destructive)').show();
            }
        })
        .fail(function () {
            $msg.text('✕ সার্ভারে সংযোগ ত্রুটি').css('color', 'var(--gf-destructive)').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('সেটিংস সংরক্ষণ করুন');
            setTimeout(function () { $msg.fadeOut(); }, 3000);
        });
    });

    /* ── Support Ticket ──────────────────────────────────────────────── */

    $(document).on('click', '#gf-support-submit', function () {
        var subject = $('#gf-support-subject').val().trim();
        var message = $('#gf-support-message').val().trim();
        var $msg    = $('#gf-support-msg');

        if (!subject || !message) {
            $msg.text('বিষয় ও বিস্তারিত আবশ্যক').css('color', 'var(--gf-destructive)').show();
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('পাঠানো হচ্ছে...');
        $msg.hide();

        $.post(data.ajaxUrl, {
            action:   'guardify_support_ticket',
            _wpnonce: data.nonce,
            subject:  subject,
            message:  message
        })
        .done(function (res) {
            if (res.success) {
                $msg.text('✓ টিকেট পাঠানো হয়েছে').css('color', 'var(--gf-success)').show();
                $('#gf-support-subject').val('');
                $('#gf-support-message').val('');
            } else {
                $msg.text('✕ ' + (res.data || 'টিকেট পাঠানো যায়নি')).css('color', 'var(--gf-destructive)').show();
            }
        })
        .fail(function () {
            $msg.text('✕ সার্ভারে সংযোগ ত্রুটি').css('color', 'var(--gf-destructive)').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('টিকেট পাঠান');
            setTimeout(function () { $msg.fadeOut(); }, 5000);
        });
    });

    /* ── Update Check ──────────────────────────────────────────────────── */

    $(document).on('click', '#gf-check-update-btn', function () {
        var $btn = $(this);
        var $msg = $('#gf-update-msg');

        $btn.prop('disabled', true).text('চেক হচ্ছে...');
        $msg.hide();

        $.post(data.ajaxUrl, {
            action:   'guardify_check_update',
            _wpnonce: data.nonce,
        })
        .done(function (res) {
            if (res.success) {
                var color = res.data.has_update ? 'var(--gf-warning, #d97706)' : 'var(--gf-success)';
                $msg.text(res.data.message).css('color', color).show();
                if (res.data.has_update) {
                    // Reload so WP update nag appears
                    setTimeout(function () { location.reload(); }, 2000);
                }
            } else {
                $msg.text('✕ ' + (res.data || 'আপডেট চেক ব্যর্থ হয়েছে')).css('color', 'var(--gf-destructive)').show();
            }
        })
        .fail(function () {
            $msg.text('✕ সার্ভারে সংযোগ ত্রুটি').css('color', 'var(--gf-destructive)').show();
        })
        .always(function () {
            $btn.prop('disabled', false).text('আপডেট চেক করুন');
        });
    });

    /* ── Connection Method Tabs ───────────────────────────────────────── */

    $(document).on('click', '.gf-connect-tab', function () {
        var method = $(this).data('method');
        $('.gf-connect-tab').removeClass('active').css({ 'border-bottom-color': 'transparent', 'color': 'var(--gf-text-muted, #6b7280)' });
        $(this).addClass('active').css({ 'border-bottom-color': 'var(--gf-primary, #3b82f6)', 'color': 'var(--gf-primary, #3b82f6)' });
        $('#gf-method-auto, #gf-method-manual').hide();
        $('#gf-method-' + method).show();
        hideMsg();
    });

    // Style active tab on load
    $('.gf-connect-tab.active').css({ 'border-bottom-color': 'var(--gf-primary, #3b82f6)', 'color': 'var(--gf-primary, #3b82f6)' });

    /* ── Auto-Fetch (Login & Connect) ─────────────────────────────────── */

    $(document).on('submit', '#gf-auto-fetch-form', function (e) {
        e.preventDefault();

        var email    = $('#gf-login-email').val().trim();
        var password = $('#gf-login-password').val();

        if (!email || !password) {
            showMsg('error', 'ইমেইল ও পাসওয়ার্ড দিন।');
            return;
        }

        var $btn = $('#gf-auto-fetch-btn');
        $btn.prop('disabled', true).text('লগইন হচ্ছে...');
        hideMsg();

        $.post(data.ajaxUrl, {
            action:   'guardify_auto_fetch',
            _wpnonce: data.nonce,
            email:    email,
            password: password
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data || {};
                var planMap = { free: 'Free', starter: 'Starter', business: 'Business' };
                var planName = planMap[d.plan] || d.plan || 'Free';
                var sms = d.sms_balance !== undefined ? d.sms_balance : 0;
                var info = '✅ সফলভাবে সংযুক্ত হয়েছে!\n\n';
                info += '📦 প্ল্যান: ' + planName + '\n';
                info += '💬 SMS ব্যালেন্স: ' + sms + '\n';
                if (d.expires_at) {
                    var exp = new Date(d.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    info += '📅 মেয়াদ: ' + (days > 0 ? days + ' দিন বাকি' : 'মেয়াদ শেষ');
                }
                showMsg('success', info.replace(/\n/g, '<br>'));
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                showMsg('error', res.data || 'সংযোগ ব্যর্থ হয়েছে।');
                $btn.prop('disabled', false).text('লগইন ও কানেক্ট');
            }
        })
        .fail(function () {
            showMsg('error', 'সার্ভারে সংযোগ করা যায়নি।');
            $btn.prop('disabled', false).text('লগইন ও কানেক্ট');
        });
    });

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
