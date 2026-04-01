/**
 * Guardify Repeat Order Blocker — Full phone validation + popup + place-order control.
 * Mirrors OrderGuard repeat-order-blocker behaviour.
 */
(function ($) {
    'use strict';

    if (typeof guardifyRepeat === 'undefined') return;

    var cfg = guardifyRepeat;
    var billingPhone = $('#billing_phone');
    if (!billingPhone.length) return;

    var placeBtn = $('#place_order');

    // Global flags (used by wp_footer prevention script too)
    window.guardifyPhoneBlocked = false;
    window.guardifyPhoneValidated = false;
    window.guardifyRepeatOrderBlocked = false;

    var lastCheckedPhone = '';
    var lastBlockedPhone = '';

    /* ── Place order button control ── */
    function togglePlaceOrder(disabled, text) {
        if (placeBtn.length) {
            placeBtn.prop('disabled', disabled);
            if (text) placeBtn.text(text);
        }
    }

    // Initially disable until phone is validated
    if (cfg.disablePlaceOrder) {
        togglePlaceOrder(true, cfg.invalidText);
    }

    /* ── Inline error container (used when popup disabled) ── */
    var errorContainer = $('.gf-repeat-warning');
    if (!errorContainer.length && !cfg.showPopup) {
        billingPhone.after('<div class="gf-repeat-warning" style="display:none;margin-top:6px;padding:8px 12px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:6px;font-size:13px;"></div>');
        errorContainer = $('.gf-repeat-warning');
    }

    // Loader
    var loader = $('<span class="gf-repeat-loader" style="display:none;margin-left:8px;"><small>যাচাই করা হচ্ছে...</small></span>');
    billingPhone.after(loader);

    /* ── Popup ── */
    $('body').append(
        '<div class="guardify-repeat-popup" style="display:none;">' +
            '<div class="guardify-repeat-popup-content">' +
                '<h2>অর্ডার ব্লক করা হয়েছে</h2>' +
                '<p class="gf-repeat-popup-msg"></p>' +
                '<div class="gf-repeat-popup-warn">অনুগ্রহ করে নির্ধারিত সময় শেষ হওয়ার পর আবার চেষ্টা করুন।</div>' +
                '<div class="gf-repeat-popup-actions">' +
                    '<button class="gf-repeat-popup-close">বুঝেছি</button>' +
                    (cfg.supportNumber ? '<a href="tel:' + cfg.supportNumber + '" class="gf-repeat-popup-call"><span class="dashicons dashicons-phone"></span> কল করুন</a>' : '') +
                '</div>' +
            '</div>' +
        '</div>'
    );

    var popup = $('.guardify-repeat-popup');
    var popupMsg = $('.gf-repeat-popup-msg');
    popup.find('.gf-repeat-popup-close').on('click', function () { popup.fadeOut(300); });

    // Popup styles
    $('<style>' +
        '.guardify-repeat-popup{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.5)}' +
        '.guardify-repeat-popup-content{background:#fff;border-radius:12px;width:90%;max-width:420px;padding:32px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2)}' +
        '.guardify-repeat-popup-content h2{margin:0 0 12px;font-size:20px;font-weight:700;color:#dc2626}' +
        '.gf-repeat-popup-msg{margin:0 0 12px;font-size:14px;color:#4b5563;line-height:1.6}' +
        '.gf-repeat-popup-warn{margin:0 0 16px;padding:10px;background:#fef3c7;border-radius:8px;font-size:13px;color:#92400e}' +
        '.gf-repeat-popup-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}' +
        '.gf-repeat-popup-close{padding:10px 24px;border:none;border-radius:8px;background:#dc2626;color:#fff;font-size:14px;font-weight:600;cursor:pointer}' +
        '.gf-repeat-popup-close:hover{background:#b91c1c}' +
        '.gf-repeat-popup-call{display:inline-flex;align-items:center;gap:4px;padding:10px 24px;border:1px solid #d1d5db;border-radius:8px;background:#fff;color:#374151;font-size:14px;font-weight:600;text-decoration:none}' +
        '.gf-repeat-popup-call:hover{background:#f3f4f6}' +
    '</style>').appendTo('head');

    // Global popup show function (called by wp_footer script too)
    window.showGuardifyRepeatPopup = function (message) {
        if (cfg.showPopup) {
            popupMsg.text(message);
            popup.css('display', 'flex').hide().fadeIn(300);
        }
    };

    /* ── Phone format check ── */
    function isValidBDPhone(phone) {
        phone = phone.replace(/[^\d+]/g, '');
        if (phone.indexOf('+88') === 0) return phone.length === 14;
        if (phone.indexOf('88') === 0) return phone.length === 13;
        return phone.length === 11 && phone.indexOf('0') === 0;
    }

    /* ── Core AJAX check ── */
    function checkPhoneNumber() {
        var raw = billingPhone.val().trim();

        if (!raw) {
            resetState();
            if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.invalidText);
            return;
        }

        if (!isValidBDPhone(raw)) {
            resetState();
            if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.invalidText);
            return;
        }

        if (raw === lastCheckedPhone) return;
        lastCheckedPhone = raw;

        loader.show();
        if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.validatingText);

        $.post(cfg.ajaxUrl, {
            action: 'guardify_check_repeat',
            nonce: cfg.nonce,
            phone: raw
        }, function (res) {
            loader.hide();

            if (res.data && res.data.blocked) {
                window.guardifyPhoneBlocked = true;
                window.guardifyPhoneValidated = false;
                window.guardifyRepeatOrderBlocked = true;
                lastBlockedPhone = raw;

                if (cfg.showPopup) {
                    if (errorContainer.length) errorContainer.hide();
                    window.showGuardifyRepeatPopup(res.data.message);
                } else if (errorContainer.length) {
                    errorContainer.html(escapeHtml(res.data.message)).show();
                }

                if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.invalidText);

                $(document).trigger('guardify_repeat_order_blocked', [raw, res.data.message]);
            } else {
                resetState();
                if (cfg.disablePlaceOrder) togglePlaceOrder(false, cfg.placeOrderButtonText);
                $(document).trigger('guardify_repeat_order_allowed', [raw]);
            }
        }).fail(function () {
            loader.hide();
            resetState();
            if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.invalidText);
        });
    }

    function resetState() {
        window.guardifyPhoneBlocked = false;
        window.guardifyPhoneValidated = false;
        window.guardifyRepeatOrderBlocked = false;
        lastBlockedPhone = '';
        if (errorContainer.length) errorContainer.hide();
    }

    /* ── Events ── */
    var debounceTimer = null;

    billingPhone.on('input', function () {
        var val = billingPhone.val().trim();
        window.guardifyPhoneValidated = false;
        window.guardifyPhoneBlocked = false;
        window.guardifyRepeatOrderBlocked = false;

        if (val !== lastBlockedPhone) {
            if (errorContainer.length) errorContainer.hide();
            lastBlockedPhone = '';
        }

        if (cfg.disablePlaceOrder) togglePlaceOrder(true, cfg.invalidText);

        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(checkPhoneNumber, 500);
    });

    billingPhone.on('blur change', function () {
        checkPhoneNumber();
    });

    billingPhone.on('focus', function () {
        var val = billingPhone.val().trim();
        if (val !== lastBlockedPhone) {
            if (errorContainer.length) errorContainer.hide();
            window.guardifyPhoneBlocked = false;
            window.guardifyRepeatOrderBlocked = false;
            lastBlockedPhone = '';
        }
    });

    // Check on page load if field already has a value
    if (billingPhone.val().trim()) {
        setTimeout(checkPhoneNumber, 1000);
    }

    // Block form submission via WC event
    $(document.body).on('checkout_place_order', function () {
        if (window.guardifyPhoneBlocked === true) return false;
        return true;
    });

    /* ── Utility ── */
    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
