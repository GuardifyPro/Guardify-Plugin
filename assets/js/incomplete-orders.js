/**
 * Guardify Incomplete Orders — Captures checkout form data periodically.
 */
(function ($) {
    'use strict';

    if (typeof guardifyIncomplete === 'undefined') return;

    var lastPhone = '';
    var debounceTimer = null;

    // Capture on phone field change (debounced)
    $(document).on('change blur', '#billing_phone', function () {
        scheduleCapture();
    });

    // Also capture when address fields change
    $(document).on('change', '#billing_first_name, #billing_last_name, #billing_address_1, #billing_city', function () {
        scheduleCapture();
    });

    function scheduleCapture() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(captureData, 2000);
    }

    function captureData() {
        var phone = $('#billing_phone').val();
        if (!phone || phone.length < 10) return;

        // Don't re-send if phone hasn't changed and we already sent
        var cleanPhone = phone.replace(/[\s\-]/g, '').replace(/^\+?88/, '');
        if (cleanPhone === lastPhone) return;
        lastPhone = cleanPhone;

        var firstName = $('#billing_first_name').val() || '';
        var lastName = $('#billing_last_name').val() || '';

        $.post(guardifyIncomplete.ajaxUrl, {
            action: 'guardify_store_incomplete',
            nonce: guardifyIncomplete.nonce,
            phone: phone,
            name: (firstName + ' ' + lastName).trim(),
            address: $('#billing_address_1').val() || '',
            city: $('#billing_city').val() || ''
        });
    }

})(jQuery);
