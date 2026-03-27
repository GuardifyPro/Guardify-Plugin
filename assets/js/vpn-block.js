/**
 * Guardify VPN/Proxy Block — Auto-checks visitor IP on checkout/cart page.
 */
(function ($) {
    'use strict';

    if (typeof guardifyVPN === 'undefined') return;

    var checked = false;

    $(document).ready(function () {
        checkVPN();
    });

    function checkVPN() {
        if (checked) return;
        checked = true;

        $.post(guardifyVPN.ajaxUrl, {
            action: 'guardify_check_vpn',
            nonce: guardifyVPN.nonce
        }, function (res) {
            if (res.success && res.data && res.data.risk_level === 'high_risk') {
                showPopup();
                disableCheckout();
            }
        });
    }

    function showPopup() {
        $('#guardify-vpn-popup').show();
    }

    function disableCheckout() {
        // Disable place order button
        $('#place_order').prop('disabled', true).css('opacity', '0.5');

        // Block form submission
        $('form.checkout').on('submit.guardifyVPN', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            showPopup();
            return false;
        });

        $(document.body).on('checkout_place_order.guardifyVPN', function () {
            return false;
        });
    }

})(jQuery);
