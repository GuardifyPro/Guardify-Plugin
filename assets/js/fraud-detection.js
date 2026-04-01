/**
 * Guardify Pro — Fraud Detection Frontend
 * 1. Generates device fingerprint and tracks visits.
 * 2. Checks if phone is in the block-list (AJAX) and prevents checkout.
 */
(function ($) {
    'use strict';

    if (typeof guardifyFraud === 'undefined') return;

    /* ── Device fingerprint ── */
    function generateDeviceId() {
        var ts = Date.now().toString(36);
        var ua = navigator.userAgent || '';
        var lang = navigator.language || '';
        var sw = window.screen.width;
        var sh = window.screen.height;
        var tz = new Date().getTimezoneOffset();

        var fingerprint = ua + lang + sw + sh + tz;
        var hash = 0;
        for (var i = 0; i < fingerprint.length; i++) {
            var ch = fingerprint.charCodeAt(i);
            hash = ((hash << 5) - hash) + ch;
            hash = hash & hash;
        }

        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var rand = '';
        for (var j = 0; j < 12; j++) {
            rand += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        return 'gf_' + ts + '_' + Math.abs(hash).toString(16) + '_' + rand;
    }

    function getDeviceId() {
        var key = 'guardify_device_id';
        var id = localStorage.getItem(key);

        if (!id) {
            var match = document.cookie.match(new RegExp('(^| )' + key + '=([^;]+)'));
            id = match ? match[2] : null;
        }

        if (!id) {
            id = generateDeviceId();
        }

        try { localStorage.setItem(key, id); } catch (e) {}
        document.cookie = key + '=' + id + '; path=/; max-age=31536000; SameSite=Strict';

        return id;
    }

    function trackVisit() {
        var deviceId = getDeviceId();
        $.post(guardifyFraud.ajaxUrl, {
            action: 'guardify_track_visit',
            nonce: guardifyFraud.nonce,
            device_id: deviceId
        });
    }

    /* ── Phone block check on checkout ── */
    var phoneBlocked = false;

    function checkPhoneBlocked(phone) {
        phone = phone.replace(/[\s\-]/g, '').replace(/^\+?88/, '');
        if (!/^01[3-9]\d{8}$/.test(phone)) return;

        $.post(guardifyFraud.ajaxUrl, {
            action: 'guardify_check_phone_blocked',
            nonce: guardifyFraud.nonce,
            phone: phone
        }, function (res) {
            if (res.success && res.data && res.data.blocked) {
                phoneBlocked = true;
                window.guardifyFraudPhoneBlocked = true;
                $('#place_order').prop('disabled', true).css('opacity', '0.5');
                // Show inline warning below phone field
                if (!$('.gf-fraud-phone-warn').length) {
                    $('#billing_phone').after(
                        '<div class="gf-fraud-phone-warn" style="margin-top:6px;padding:8px 12px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:6px;font-size:13px;">' +
                        'এই ফোন নম্বর ব্লক করা হয়েছে।</div>'
                    );
                }
            } else {
                phoneBlocked = false;
                window.guardifyFraudPhoneBlocked = false;
                $('.gf-fraud-phone-warn').remove();
                // Only re-enable if repeat blocker hasn't blocked it
                if (!window.guardifyPhoneBlocked) {
                    $('#place_order').prop('disabled', false).css('opacity', '1');
                }
            }
        });
    }

    /* ── Initialization ── */
    $(document).ready(function () {
        var isCheckout = $('body').hasClass('woocommerce-checkout') || $('form.checkout').length;

        if (isCheckout) {
            trackVisit();

            // Phone block check with debounce
            var phoneTimer = null;
            var $phone = $('#billing_phone');

            if ($phone.length) {
                $phone.on('change blur', function () {
                    checkPhoneBlocked($(this).val());
                });
                $phone.on('input', function () {
                    clearTimeout(phoneTimer);
                    var val = $(this).val();
                    phoneTimer = setTimeout(function () { checkPhoneBlocked(val); }, 600);
                });

                // Check on load if value exists
                if ($phone.val().trim()) {
                    setTimeout(function () { checkPhoneBlocked($phone.val()); }, 800);
                }
            }

            // Block checkout form if phone is blocked
            $(document.body).on('checkout_place_order', function () {
                if (phoneBlocked) return false;
                return true;
            });
        }
    });

})(jQuery);
