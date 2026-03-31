/**
 * Guardify Pro — Phone Validation
 * Live Bangladesh phone number validation on checkout page.
 */
(function ($) {
    'use strict';

    if (typeof guardifyPhoneVal === 'undefined') return;

    var phoneField = $('#billing_phone');
    if (!phoneField.length) return;

    // Add validation message container after phone field
    var validMsg = $('<div class="gf-phone-validation-msg"></div>');
    phoneField.after(validMsg);

    // Wrap phone field for icon positioning
    phoneField.wrap('<div class="gf-phone-field-wrap"></div>');

    // Add status icon
    var statusIcon = $('<span class="gf-phone-status-icon"></span>');
    phoneField.after(statusIcon);

    var successSvg = '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#16a34a" stroke-width="2"/><path d="M8 12l3 3 5-5" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    var errorSvg   = '<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#dc2626" stroke-width="2"/><path d="M15 9l-6 6M9 9l6 6" stroke="#dc2626" stroke-width="2" stroke-linecap="round"/></svg>';

    /**
     * Validate Bangladesh mobile phone: exactly 11 digits, 01[3-9]XXXXXXXX
     */
    function isValidPhone(phone) {
        return /^01[3-9]\d{8}$/.test(phone);
    }

    /**
     * Clean phone number: strip spaces, dashes, +88 prefix
     */
    function cleanPhone(raw) {
        var p = raw.replace(/[\s\-()]/g, '').replace(/^\+?88/, '');
        return p;
    }

    function showError(msg) {
        validMsg.text(msg || guardifyPhoneVal.message).addClass('gf-visible');
        phoneField.addClass('gf-phone-invalid').removeClass('gf-phone-valid');
        statusIcon.html(errorSvg).addClass('gf-visible');
    }

    function showSuccess() {
        validMsg.removeClass('gf-visible');
        phoneField.addClass('gf-phone-valid').removeClass('gf-phone-invalid');
        statusIcon.html(successSvg).addClass('gf-visible');
    }

    function clearAll() {
        validMsg.removeClass('gf-visible');
        phoneField.removeClass('gf-phone-invalid gf-phone-valid');
        statusIcon.removeClass('gf-visible').empty();
    }

    // Live validation on input (debounced)
    var inputTimer;
    phoneField.on('input', function () {
        clearTimeout(inputTimer);
        var raw = $(this).val().trim();
        var phone = cleanPhone(raw);

        if (phone.length === 0) {
            clearAll();
            return;
        }

        inputTimer = setTimeout(function () {
            if (phone.length >= 11) {
                if (isValidPhone(phone)) {
                    showSuccess();
                } else {
                    showError();
                }
            } else if (phone.length > 0 && phone.length < 11) {
                // Show hint while typing if it looks wrong
                if (phone.length >= 3 && !/^01[3-9]/.test(phone)) {
                    showError('সঠিক বাংলাদেশী ফোন নম্বর দিন (01XXXXXXXXX)');
                } else {
                    clearAll();
                }
            }
        }, 300);
    });

    // Validate on blur
    phoneField.on('blur', function () {
        var phone = cleanPhone($(this).val().trim());
        if (phone.length === 0) {
            clearAll();
            return;
        }
        if (!isValidPhone(phone)) {
            showError();
            // Shake animation
            phoneField.removeClass('gf-shake').addClass('gf-shake');
            setTimeout(function () { phoneField.removeClass('gf-shake'); }, 600);
        }
    });

    // Clear invalid state on focus
    phoneField.on('focus', function () {
        phoneField.removeClass('gf-phone-invalid');
    });

    // Block form submission if phone is invalid
    $('form.checkout').on('checkout_place_order', function () {
        var phone = cleanPhone(phoneField.val().trim());
        if (phone.length > 0 && !isValidPhone(phone)) {
            showError();
            $('html, body').animate({ scrollTop: phoneField.offset().top - 100 }, 400);
            return false;
        }
        return true;
    });

})(jQuery);
