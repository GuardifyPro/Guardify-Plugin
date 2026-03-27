/**
 * Guardify Repeat Order Blocker — Live phone check on checkout.
 */
(function ($) {
    'use strict';

    if (typeof guardifyRepeat === 'undefined') return;

    var debounceTimer = null;
    var phoneBlocked = false;

    // Check phone on blur + debounced input
    $(document).on('change blur', '#billing_phone', function () {
        checkPhone($(this).val());
    });

    $(document).on('input', '#billing_phone', function () {
        clearTimeout(debounceTimer);
        var phone = $(this).val();
        debounceTimer = setTimeout(function () {
            checkPhone(phone);
        }, 600);
    });

    function checkPhone(phone) {
        phone = phone.replace(/[\s\-]/g, '').replace(/^\+?88/, '');
        if (!/^01[3-9]\d{8}$/.test(phone)) {
            clearWarning();
            phoneBlocked = false;
            return;
        }

        $.post(guardifyRepeat.ajaxUrl, {
            action: 'guardify_check_repeat',
            nonce: guardifyRepeat.nonce,
            phone: phone
        }, function (res) {
            if (!res.success && res.data && res.data.blocked) {
                phoneBlocked = true;
                showWarning(res.data.message);
                $('#place_order').prop('disabled', true).css('opacity', '0.5');
            } else {
                phoneBlocked = false;
                clearWarning();
                $('#place_order').prop('disabled', false).css('opacity', '1');
            }
        });
    }

    // Block form submission if phone is blocked
    $(document.body).on('checkout_place_order', function () {
        if (phoneBlocked) {
            return false;
        }
        return true;
    });

    function showWarning(message) {
        clearWarning();
        var $field = $('#billing_phone_field');
        $field.append('<span class="gf-repeat-warning" style="display:block;margin-top:6px;padding:8px 12px;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:6px;font-size:13px;">' + escapeHtml(message) + '</span>');
    }

    function clearWarning() {
        $('.gf-repeat-warning').remove();
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

})(jQuery);
