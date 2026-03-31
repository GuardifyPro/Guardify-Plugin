/**
 * Guardify Pro — Smart Order Filter
 * Live DP ratio checking on checkout page with visual indicators and popup.
 */
(function ($) {
    'use strict';

    if (typeof guardifySmartFilter === 'undefined') return;
    if (guardifySmartFilter.enabled !== 'yes') return;

    var phoneField = $('#billing_phone');
    if (!phoneField.length) return;

    var lastCheckedPhone = '';
    var isProcessing = false;
    var popupShown = false;

    // Add DP status indicator below phone field
    var dpStatus = $('<div class="gf-dp-status"></div>');
    phoneField.closest('.form-row, .woocommerce-input-wrapper, .gf-phone-field-wrap')
        .parent().append(dpStatus);

    // If phone-validation already wrapped the field, append to parent instead
    if (!dpStatus.parent().length) {
        phoneField.parent().append(dpStatus);
    }

    // Inject popup HTML
    $('body').append(
        '<div class="gf-dp-popup" id="gf-dp-popup">' +
            '<div class="gf-dp-popup-overlay"></div>' +
            '<div class="gf-dp-popup-content">' +
                '<div class="gf-dp-popup-header">' +
                    '<h3>স্মার্ট অর্ডার ফিল্টার</h3>' +
                    '<button type="button" class="gf-dp-popup-close">' +
                        '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>' +
                    '</button>' +
                '</div>' +
                '<div class="gf-dp-popup-body">' +
                    '<div class="gf-dp-popup-icon">' +
                        '<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>' +
                    '</div>' +
                    '<div class="gf-dp-popup-message"></div>' +
                    '<div class="gf-dp-popup-actions">' +
                        '<button type="button" class="gf-dp-popup-btn gf-btn-secondary" id="gf-dp-popup-close-btn">বন্ধ করুন</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
        '</div>'
    );

    var popup = $('#gf-dp-popup');
    var popupMessage = popup.find('.gf-dp-popup-message');

    // SVG icons
    var spinnerSvg = '<svg viewBox="0 0 24 24" width="20" height="20" class="gf-dp-spinner"><path fill="#6366f1" d="M12 2a10 10 0 0 1 10 10 1 1 0 0 1-2 0 8 8 0 0 0-8-8 1 1 0 0 1 0-2z"/></svg>';
    var successSvg = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#16a34a" d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-.997-6l7.07-7.071-1.414-1.414-5.656 5.657-2.829-2.829-1.414 1.414L11.003 16z"/></svg>';
    var warnSvg    = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#d97706" d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-7v2h2v-2h-2zm0-8v6h2V7h-2z"/></svg>';
    var errorSvg   = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="#dc2626" d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm0-11.414L9.172 7.757 7.757 9.172 10.586 12l-2.829 2.828 1.415 1.415L12 13.414l2.828 2.829 1.415-1.415L13.414 12l2.829-2.828-1.415-1.415L12 10.586z"/></svg>';

    // Close popup handlers
    popup.on('click', '.gf-dp-popup-overlay, .gf-dp-popup-close, #gf-dp-popup-close-btn', function () {
        popup.removeClass('gf-active gf-popup-error gf-popup-warning');
        popupShown = false;
    });

    function showStatus(type, text) {
        var icon = '';
        switch (type) {
            case 'loading': icon = spinnerSvg; break;
            case 'success': icon = successSvg; break;
            case 'warning': icon = warnSvg;    break;
            case 'error':   icon = errorSvg;   break;
        }
        var cls = type === 'success' ? 'gf-text-success' : (type === 'warning' ? 'gf-text-warning' : (type === 'error' ? 'gf-text-danger' : ''));
        dpStatus.html(
            '<span class="gf-dp-status-icon">' + icon + '</span>' +
            (text ? '<span class="gf-dp-status-text ' + cls + '">' + escHtml(text) + '</span>' : '')
        ).addClass('gf-visible');
    }

    function hideStatus() {
        dpStatus.removeClass('gf-visible').empty();
    }

    function showPopup(type, message) {
        if (popupShown) return;
        popupShown = true;
        popup.removeClass('gf-popup-error gf-popup-warning').addClass('gf-active gf-popup-' + type);
        popupMessage.html(escHtml(message));
    }

    function cleanPhone(raw) {
        return raw.replace(/[\s\-()]/g, '').replace(/^\+?88/, '');
    }

    /**
     * Check DP ratio via AJAX
     */
    function checkDP(phone) {
        if (phone === lastCheckedPhone || isProcessing) return;
        lastCheckedPhone = phone;
        isProcessing = true;
        popupShown = false;

        showStatus('loading', 'DP চেক হচ্ছে...');

        $.post(guardifySmartFilter.ajaxUrl, {
            action: 'guardify_check_dp',
            nonce: guardifySmartFilter.nonce,
            phone: phone
        })
        .done(function (res) {
            if (res.success && res.data) {
                var d = res.data;
                var dp = parseFloat(d.dp_ratio || 0);
                var threshold = parseFloat(guardifySmartFilter.threshold || 70);

                if (dp >= threshold) {
                    // Good DP
                    showStatus('success', 'DP ' + dp.toFixed(1) + '% ✓');
                    window.guardifyDpBlocked = false;
                    enablePlaceOrder();
                } else if (d.total == 0 && guardifySmartFilter.skipNew === 'yes') {
                    // New customer + skip enabled
                    showStatus('success', 'নতুন গ্রাহক ✓');
                    window.guardifyDpBlocked = false;
                    enablePlaceOrder();
                } else {
                    // Low DP
                    var action = guardifySmartFilter.action || 'block';

                    if (action === 'block') {
                        showStatus('error', 'DP ' + dp.toFixed(1) + '% — ব্লক');
                        showPopup('error', 'এই ফোন নম্বরের ডেলিভারি পারফরম্যান্স অপর্যাপ্ত (' + dp.toFixed(1) + '%)। অর্ডার প্লেস করা যাচ্ছে না।');
                        window.guardifyDpBlocked = true;
                        disablePlaceOrder();
                    } else if (action === 'otp') {
                        showStatus('warning', 'DP ' + dp.toFixed(1) + '% — OTP প্রয়োজন');
                        window.guardifyDpBlocked = false;
                        enablePlaceOrder();
                    } else {
                        // flag — just show warning
                        showStatus('warning', 'DP ' + dp.toFixed(1) + '% — ফ্ল্যাগ');
                        window.guardifyDpBlocked = false;
                        enablePlaceOrder();
                    }
                }
            } else {
                // API error — fail open
                hideStatus();
                window.guardifyDpBlocked = false;
                enablePlaceOrder();
            }
        })
        .fail(function () {
            // Network error — fail open
            hideStatus();
            window.guardifyDpBlocked = false;
            enablePlaceOrder();
        })
        .always(function () {
            isProcessing = false;
        });
    }

    function disablePlaceOrder() {
        $('#place_order').prop('disabled', true).css('opacity', '0.5');
    }

    function enablePlaceOrder() {
        $('#place_order').prop('disabled', false).css('opacity', '1');
    }

    // Debounced phone input check
    var dpTimer;
    phoneField.on('input', function () {
        clearTimeout(dpTimer);
        var phone = cleanPhone($(this).val().trim());
        popupShown = false;
        lastCheckedPhone = '';

        if (phone.length >= 11 && /^01[3-9]\d{8}$/.test(phone)) {
            dpTimer = setTimeout(function () {
                checkDP(phone);
            }, 800);
        } else {
            hideStatus();
            window.guardifyDpBlocked = false;
            enablePlaceOrder();
        }
    });

    // Also check on blur
    phoneField.on('blur', function () {
        var phone = cleanPhone($(this).val().trim());
        if (phone.length === 11 && /^01[3-9]\d{8}$/.test(phone) && phone !== lastCheckedPhone) {
            checkDP(phone);
        }
    });

    // Block form submission if DP blocked
    $(document.body).on('checkout_place_order', function () {
        if (window.guardifyDpBlocked) {
            showPopup('error', 'এই ফোন নম্বরের ডেলিভারি পারফরম্যান্স অপর্যাপ্ত। অর্ডার প্লেস করা যাচ্ছে না।');
            return false;
        }
        return true;
    });

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
