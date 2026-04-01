/**
 * Guardify Incomplete Orders — Frontend checkout capture.
 * Monitors form fields with debounce, captures via AJAX,
 * and uses sendBeacon on page exit for reliable data capture.
 */
(function ($) {
    'use strict';

    if (typeof guardifyIncomplete === 'undefined') return;
    if (!$('body').hasClass('woocommerce-checkout')) return;

    var phoneStored = false;
    var formSubmitted = false;
    var debounceTimer = null;
    var minFields = 3;

    // Mark form as submitted to prevent capture
    $(document.body).on('checkout_place_order', function () {
        formSubmitted = true;
        return true;
    });
    $('form.woocommerce-checkout').on('submit', function () {
        formSubmitted = true;
        return true;
    });

    function collectFormData() {
        return {
            phone: $('#billing_phone').val() || '',
            name: (($('#billing_first_name').val() || '') + ' ' + ($('#billing_last_name').val() || '')).trim(),
            email: $('#billing_email').val() || '',
            address: $('#billing_address_1').val() || '',
            city: $('#billing_city').val() || '',
            state: $('#billing_state').val() || '',
            country: $('#billing_country').val() || '',
            postcode: $('#billing_postcode').val() || ''
        };
    }

    function shouldStore(data) {
        if (formSubmitted || phoneStored) return false;
        if (!data.phone || data.phone.length < 10) return false;

        var filled = 0;
        $.each(data, function (k, v) {
            if (v && v.trim() !== '') filled++;
        });
        return filled >= minFields;
    }

    // Monitor checkout form changes (2s debounce)
    $('form.checkout').on('change input', 'input, select, textarea', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            if (formSubmitted) return;
            var data = collectFormData();
            if (shouldStore(data)) storeIncomplete(data);
        }, 2000);
    });

    function storeIncomplete(data) {
        if (formSubmitted) return;

        data.action = 'guardify_store_incomplete';
        data.nonce = guardifyIncomplete.nonce;

        $.ajax({
            url: guardifyIncomplete.ajaxUrl,
            type: 'POST',
            data: data,
            success: function (res) {
                if (res.success) {
                    phoneStored = true;
                }
            }
        });
    }

    // Capture on page exit via sendBeacon
    $(window).on('beforeunload', function () {
        if (formSubmitted || phoneStored) return;

        var data = collectFormData();
        if (!shouldStore(data)) return;

        if (navigator.sendBeacon) {
            var fd = new FormData();
            fd.append('action', 'guardify_store_incomplete');
            fd.append('nonce', guardifyIncomplete.nonce);
            $.each(data, function (k, v) { fd.append(k, v); });
            navigator.sendBeacon(guardifyIncomplete.ajaxUrl, fd);
        } else {
            data.action = 'guardify_store_incomplete';
            data.nonce = guardifyIncomplete.nonce;
            $.ajax({
                url: guardifyIncomplete.ajaxUrl,
                type: 'POST',
                async: false,
                data: data
            });
        }
    });
})(jQuery);
