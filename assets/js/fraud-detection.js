/**
 * Guardify Pro — Fraud Detection Frontend
 * Generates device fingerprint and tracks user visits for fraud detection.
 */
(function ($) {
    'use strict';

    if (typeof guardifyFraud === 'undefined') return;

    /**
     * Generate a browser-based device fingerprint ID.
     * Combines timestamp + browser attributes hash + random string.
     */
    function generateDeviceId() {
        var ts = Date.now().toString(36);
        var ua = navigator.userAgent || '';
        var lang = navigator.language || '';
        var sw = window.screen.width;
        var sh = window.screen.height;
        var tz = new Date().getTimezoneOffset();

        // Simple hash of browser attributes
        var fingerprint = ua + lang + sw + sh + tz;
        var hash = 0;
        for (var i = 0; i < fingerprint.length; i++) {
            var ch = fingerprint.charCodeAt(i);
            hash = ((hash << 5) - hash) + ch;
            hash = hash & hash; // 32-bit integer
        }

        // Random suffix
        var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        var rand = '';
        for (var j = 0; j < 12; j++) {
            rand += chars.charAt(Math.floor(Math.random() * chars.length));
        }

        return 'gf_' + ts + '_' + Math.abs(hash).toString(16) + '_' + rand;
    }

    /**
     * Get or create a persistent device ID via cookie + localStorage.
     */
    function getDeviceId() {
        var key = 'guardify_device_id';
        var id = localStorage.getItem(key);

        if (!id) {
            // Check cookie fallback
            var match = document.cookie.match(new RegExp('(^| )' + key + '=([^;]+)'));
            id = match ? match[2] : null;
        }

        if (!id) {
            id = generateDeviceId();
        }

        // Persist in both stores
        try { localStorage.setItem(key, id); } catch (e) { /* quota */ }
        document.cookie = key + '=' + id + '; path=/; max-age=31536000; SameSite=Strict';

        return id;
    }

    /**
     * Track a visit to the checkout page.
     */
    function trackVisit() {
        var deviceId = getDeviceId();

        $.post(guardifyFraud.ajaxUrl, {
            action: 'guardify_track_visit',
            nonce: guardifyFraud.nonce,
            device_id: deviceId
        });
    }

    // Initialize on checkout pages
    $(document).ready(function () {
        if ($('body').hasClass('woocommerce-checkout') || $('form.checkout').length) {
            trackVisit();
        }
    });

})(jQuery);
