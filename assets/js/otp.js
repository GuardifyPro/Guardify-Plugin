/**
 * Guardify OTP Verification — Checkout phone verification flow.
 */
(function ($) {
    'use strict';

    if (typeof guardifyOTP === 'undefined') return;

    var resendCooldown = 60; // seconds
    var timer = null;

    // Show OTP modal when place order is clicked (before form submit)
    $(document.body).on('checkout_place_order', function () {
        var verified = sessionStorage.getItem('guardify_otp_verified');
        if (verified === 'true') return true;

        var phone = $('#billing_phone').val();
        if (!phone) return true; // let WC handle empty phone

        showModal();
        sendOTP(phone);
        return false; // block checkout until verified
    });

    function showModal() {
        $('#guardify-otp-modal').show();
        $('#gf-otp-input').val('').focus();
        clearMessage();
    }

    function hideModal() {
        $('#guardify-otp-modal').hide();
    }

    // Close on overlay click
    $(document).on('click', '.gf-otp-modal-overlay', hideModal);

    // Send OTP
    function sendOTP(phone) {
        var $btn = $('#gf-otp-verify-btn');
        $btn.prop('disabled', true);
        showMessage('OTP পাঠানো হচ্ছে...', 'info');

        $.post(guardifyOTP.ajaxUrl, {
            action: 'guardify_send_otp',
            nonce: guardifyOTP.nonce,
            phone: phone
        }, function (res) {
            $btn.prop('disabled', false);
            if (res.success) {
                showMessage(res.data.message, 'success');
                startResendTimer();
            } else {
                showMessage(res.data.message || 'OTP পাঠানো যায়নি।', 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            showMessage('সার্ভারে সমস্যা হয়েছে।', 'error');
        });
    }

    // Verify OTP
    $(document).on('click', '#gf-otp-verify-btn', function () {
        var otp = $('#gf-otp-input').val().trim();
        var phone = $('#billing_phone').val();

        if (!otp || otp.length < 4) {
            showMessage('সম্পূর্ণ OTP কোড দিন।', 'error');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('যাচাই হচ্ছে...');

        $.post(guardifyOTP.ajaxUrl, {
            action: 'guardify_verify_otp',
            nonce: guardifyOTP.nonce,
            phone: phone,
            otp: otp
        }, function (res) {
            $btn.prop('disabled', false).text('ভেরিফাই করুন');
            if (res.success) {
                showMessage(res.data.message, 'success');
                sessionStorage.setItem('guardify_otp_verified', 'true');
                setTimeout(function () {
                    hideModal();
                    // Re-trigger checkout
                    $('form.checkout').trigger('submit');
                }, 800);
            } else {
                showMessage(res.data.message || 'ভুল OTP।', 'error');
            }
        }).fail(function () {
            $btn.prop('disabled', false).text('ভেরিফাই করুন');
            showMessage('সার্ভারে সমস্যা হয়েছে।', 'error');
        });
    });

    // Enter key on OTP input
    $(document).on('keypress', '#gf-otp-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#gf-otp-verify-btn').trigger('click');
        }
    });

    // Only allow digits
    $(document).on('input', '#gf-otp-input', function () {
        this.value = this.value.replace(/\D/g, '').slice(0, 6);
    });

    // Resend OTP
    $(document).on('click', '#gf-otp-resend', function (e) {
        e.preventDefault();
        var phone = $('#billing_phone').val();
        if (phone) sendOTP(phone);
    });

    function startResendTimer() {
        var remaining = resendCooldown;
        var $resend = $('#gf-otp-resend');
        var $countdown = $('.gf-otp-countdown');

        $resend.hide();
        $countdown.show();

        if (timer) clearInterval(timer);

        timer = setInterval(function () {
            remaining--;
            $countdown.text(remaining + 's');
            if (remaining <= 0) {
                clearInterval(timer);
                $countdown.hide();
                $resend.show();
            }
        }, 1000);
    }

    function showMessage(text, type) {
        var $msg = $('.gf-otp-message');
        $msg.removeClass('gf-otp-error gf-otp-success').text(text).show();
        if (type === 'error') $msg.addClass('gf-otp-error');
        else if (type === 'success') $msg.addClass('gf-otp-success');
        else $msg.addClass('gf-otp-success'); // info → success style
    }

    function clearMessage() {
        $('.gf-otp-message').removeClass('gf-otp-error gf-otp-success').text('').hide();
    }

    // Clear verification on phone change
    $(document).on('change', '#billing_phone', function () {
        sessionStorage.removeItem('guardify_otp_verified');
    });

    // Clear on page leave
    $(window).on('beforeunload', function () {
        sessionStorage.removeItem('guardify_otp_verified');
    });

})(jQuery);
