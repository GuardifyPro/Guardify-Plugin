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
                    var planMap = { none: 'কোনো প্ল্যান নেই', trial: 'Trial', starter: 'Starter', business: 'Business' };
                    $('#gf-plan-text').text(planMap[res.data.plan] || res.data.plan);
                }
                if (res.data.sms_balance !== undefined) {
                    $('#gf-sms-text').text(res.data.sms_balance.toLocaleString('bn-BD'));
                }
                if (res.data.expires_at) {
                    var exp = new Date(res.data.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    if (days > 0) {
                        $('#gf-expiry-text').text(days + ' দিন বাকি').css('color', days <= 7 ? '#ef4444' : '');
                    } else {
                        $('#gf-expiry-text').text('মেয়াদ শেষ').css('color', '#ef4444');
                    }
                } else {
                    $('#gf-expiry-text').text('কোনো মেয়াদ নেই');
                }

                // Show upgrade/renew link for trial or expired plans
                var plan = res.data.plan || 'none';
                var isExpired = res.data.expires_at && new Date(res.data.expires_at) < new Date();
                if (plan === 'trial' || plan === 'none' || isExpired) {
                    var linkText = isExpired ? '🔄 রিনিউ করুন' : (plan === 'trial' ? '⬆️ আপগ্রেড করুন' : '🚀 সাবস্ক্রিপশন নিন');
                    $('#gf-plan-text').append(
                        ' <a href="https://guardify.pro/subscription" target="_blank" ' +
                        'style="font-size:0.75rem;color:#3b82f6;text-decoration:underline;margin-left:4px;">' +
                        linkText + '</a>'
                    );
                }
            } else {
                $('#gf-status-text').text('যাচাই ব্যর্থ');
            }
        })
        .fail(function () {
            $('#gf-status-text').text('সংযোগ ত্রুটি');
        });

        /* ── Auto-fetch Steadfast balance ─────────────────────────────── */
        if ($('#gf-steadfast-balance').length) {
            $.post(data.ajaxUrl, {
                action:   'guardify_courier_balance',
                provider: 'steadfast',
                _wpnonce: data.courierNonce
            })
            .done(function (res) {
                if (res.success && res.data) {
                    var bal = res.data.current_balance !== undefined ? res.data.current_balance : res.data.balance;
                    if (bal !== undefined) {
                        $('#gf-steadfast-balance').text('৳' + Number(bal).toLocaleString('bn-BD'));
                    } else {
                        $('#gf-steadfast-balance').text('—');
                    }
                } else {
                    $('#gf-steadfast-balance').text('ত্রুটি');
                }
            })
            .fail(function () {
                $('#gf-steadfast-balance').text('—');
            });
        }
    }

    /* ── Tab Navigation ───────────────────────────────────────────────── */

    $(document).on('click', '.gf-tab', function () {
        var tab = $(this).data('tab');
        $('.gf-tab').removeClass('active');
        $(this).addClass('active');
        $('.gf-tab-content').hide();
        $('#gf-tab-' + tab).show();

        // Only show save button on settings tabs
        var saveTabs = ['features', 'smart-filter', 'notifications'];
        var $save = $('#gf-save-wrap');
        if ($save.length) {
            $save.toggle(saveTabs.indexOf(tab) !== -1);
        }
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

    // Dynamic ticket type fields
    $(document).on('change', '#gf-support-type', function () {
        var map = {
            'সমস্যা রিপোর্ট':  '.gf-field-problem',
            'ফিচার রিকোয়েস্ট': '.gf-field-feature',
            'তথ্য প্রয়োজন':   '.gf-field-info',
            'সাধারণ প্রশ্ন':    '.gf-field-general',
            'বিলিং':            '.gf-field-billing'
        };
        $('.gf-ticket-field').hide();
        var sel = map[$(this).val()];
        if (sel) $(sel).show();
    });

    // Copy ticket ID
    $(document).on('click', '#gf-ticket-id-box', function () {
        var id = $('#gf-ticket-id-val').text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(id);
        } else {
            var tmp = $('<input>').appendTo('body').val(id).select();
            document.execCommand('copy');
            tmp.remove();
        }
        var $hint = $('<div style="position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);color:#fff;padding:8px 16px;border-radius:6px;font-size:13px;z-index:100000;">টিকেট ID কপি হয়েছে!</div>').appendTo('body');
        setTimeout(function(){ $hint.fadeOut(300, function(){ $hint.remove(); }); }, 1500);
    });

    $(document).on('click', '#gf-support-submit', function () {
        var ticketType = $('#gf-support-type').val();
        var whatsapp   = $('#gf-support-whatsapp').val().trim();
        var $msg       = $('#gf-support-msg');

        // Get active textarea
        var message = '';
        var fieldMap = {
            'সমস্যা রিপোর্ট':  '#gf-support-problem',
            'ফিচার রিকোয়েস্ট': '#gf-support-feature',
            'তথ্য প্রয়োজন':   '#gf-support-info',
            'সাধারণ প্রশ্ন':    '#gf-support-general',
            'বিলিং':            '#gf-support-billing'
        };
        var activeField = fieldMap[ticketType];
        if (activeField) message = $(activeField).val().trim();

        if (!whatsapp) {
            $msg.text('WhatsApp নম্বর আবশ্যক').css('color', 'var(--gf-destructive)').show();
            return;
        }
        if (!message) {
            $msg.text('বিস্তারিত লিখুন').css('color', 'var(--gf-destructive)').show();
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;animation:spin 1s linear infinite;"></span> পাঠানো হচ্ছে...');
        $msg.hide();

        $.post(data.ajaxUrl, {
            action:      'guardify_support_ticket',
            _wpnonce:    data.nonce,
            subject:     ticketType,
            message:     message,
            whatsapp:    whatsapp,
            ticket_type: ticketType
        })
        .done(function (res) {
            if (res.success) {
                // Show success popup
                var $popup = $('#gf-ticket-popup');
                if (res.data && res.data.ticket_id) {
                    $('#gf-ticket-id-val').text(res.data.ticket_id);
                    $('#gf-ticket-id-box').show();
                } else {
                    $('#gf-ticket-id-box').hide();
                }
                $popup.css('display', 'flex');

                // Clear form
                $('#gf-support-whatsapp').val('');
                $('.gf-ticket-field textarea').val('');
            } else {
                $msg.text('✕ ' + (res.data || 'টিকেট পাঠানো যায়নি')).css('color', 'var(--gf-destructive)').show();
            }
        })
        .fail(function () {
            $msg.text('✕ সার্ভারে সংযোগ ত্রুটি').css('color', 'var(--gf-destructive)').show();
        })
        .always(function () {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-email" style="font-size:16px;width:16px;height:16px;"></span> মেসেজ পাঠান');
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
