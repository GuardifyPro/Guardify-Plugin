/**
 * Guardify Pro — Admin JavaScript
 *
 * Two layers. The first is the design system's behaviour — tabs, overlays,
 * toasts, clipboard, responsive tables — written in plain DOM API and exposed
 * as window.Guardify so page templates can drive components without each one
 * reinventing an open/close routine. The second is the page logic, which talks
 * to WordPress over AJAX and uses jQuery because that is what wp-admin already
 * loads for us.
 */

/* ══ Component layer ═══════════════════════════════════════════════════════ */

(function (window, document) {
    'use strict';

    var Guardify = window.Guardify || {};

    function toArray(list) {
        return Array.prototype.slice.call(list || []);
    }

    /* ── Toast ─────────────────────────────────────────────────────────────
       Lives in a fixed stack appended to <body>, not inside the page, so a
       message survives the card that produced it being re-rendered. */

    var toastStack = null;

    function getToastStack() {
        if (!toastStack || !toastStack.parentNode) {
            toastStack = document.createElement('div');
            toastStack.className = 'gf-toast-stack';
            toastStack.setAttribute('role', 'status');
            toastStack.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastStack);
        }
        return toastStack;
    }

    Guardify.toast = function (message, options) {
        var opts = options || {};
        var type = opts.type || 'info';
        var el = document.createElement('div');
        el.className = 'gf-toast gf-toast-' + type;

        var body = document.createElement('div');
        body.className = 'gf-toast-body';

        if (opts.title) {
            var title = document.createElement('strong');
            title.className = 'gf-toast-title';
            title.textContent = opts.title;
            body.appendChild(title);
        }

        // textContent, never innerHTML: messages routinely carry an API error
        // string that came back from the network.
        body.appendChild(document.createTextNode(message == null ? '' : String(message)));

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'gf-toast-close';
        close.setAttribute('aria-label', 'বন্ধ করুন');
        close.innerHTML = '&times;';
        close.addEventListener('click', function () { dismiss(el); });

        el.appendChild(body);
        el.appendChild(close);
        getToastStack().appendChild(el);

        var life = typeof opts.duration === 'number' ? opts.duration : 4500;
        if (life > 0) {
            window.setTimeout(function () { dismiss(el); }, life);
        }

        return el;
    };

    function dismiss(el) {
        if (!el || !el.parentNode || el.classList.contains('gf-toast-out')) {
            return;
        }
        el.classList.add('gf-toast-out');
        window.setTimeout(function () {
            if (el.parentNode) {
                el.parentNode.removeChild(el);
            }
        }, 200);
    }

    /* ── Overlays: modal and drawer ────────────────────────────────────────
       One implementation for both. An overlay that traps focus has to also
       give it back: without restoring the trigger, a keyboard user who closes
       a row's dialog lands at the top of the document and has to tab through
       the whole table again to reach the next row. */

    var FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), ' +
                    'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    var openOverlays = [];

    function resolve(target) {
        if (!target) { return null; }
        if (typeof target === 'string') { return document.querySelector(target); }
        return target.nodeType === 1 ? target : null;
    }

    function focusables(overlay) {
        return toArray(overlay.querySelectorAll(FOCUSABLE)).filter(function (el) {
            return el.offsetWidth > 0 || el.offsetHeight > 0 || el === document.activeElement;
        });
    }

    Guardify.openOverlay = function (target) {
        var overlay = resolve(target);
        if (!overlay || openOverlays.indexOf(overlay) !== -1) {
            return null;
        }

        overlay.gfReturnFocus = document.activeElement;
        overlay.classList.add('is-open');
        overlay.classList.remove('gf-hidden');
        overlay.style.display = '';
        overlay.setAttribute('aria-hidden', 'false');
        if (!overlay.hasAttribute('role')) {
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
        }

        openOverlays.push(overlay);
        document.body.classList.add('gf-overlay-open');

        var first = focusables(overlay)[0];
        if (first) {
            first.focus();
        } else {
            overlay.setAttribute('tabindex', '-1');
            overlay.focus();
        }

        return overlay;
    };

    Guardify.closeOverlay = function (target) {
        var overlay = resolve(target);
        if (!overlay) { return; }

        var i = openOverlays.indexOf(overlay);
        if (i !== -1) { openOverlays.splice(i, 1); }

        overlay.classList.remove('is-open', 'gf-modal-open');
        overlay.style.display = 'none';
        overlay.setAttribute('aria-hidden', 'true');

        if (!openOverlays.length) {
            document.body.classList.remove('gf-overlay-open');
        }

        var back = overlay.gfReturnFocus;
        overlay.gfReturnFocus = null;
        if (back && typeof back.focus === 'function' && document.contains(back)) {
            back.focus();
        }
    };

    Guardify.closeAllOverlays = function () {
        openOverlays.slice().forEach(Guardify.closeOverlay);
    };

    // Kept as aliases because "modal" is what the pages call these.
    Guardify.openModal = Guardify.openOverlay;
    Guardify.closeModal = Guardify.closeOverlay;

    document.addEventListener('click', function (e) {
        var opener = e.target.closest('[data-gf-open]');
        if (opener) {
            e.preventDefault();
            Guardify.openOverlay(opener.getAttribute('data-gf-open'));
            return;
        }

        var closer = e.target.closest('[data-gf-close]');
        if (closer) {
            e.preventDefault();
            var named = closer.getAttribute('data-gf-close');
            Guardify.closeOverlay(named ? named : closer.closest('.gf-modal-overlay, .gf-drawer-overlay'));
            return;
        }

        // A click that lands on the backdrop itself, not on the panel.
        if (e.target.classList &&
            (e.target.classList.contains('gf-modal-overlay') || e.target.classList.contains('gf-drawer-overlay'))) {
            Guardify.closeOverlay(e.target);
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!openOverlays.length) { return; }
        var overlay = openOverlays[openOverlays.length - 1];

        if (e.key === 'Escape') {
            e.preventDefault();
            Guardify.closeOverlay(overlay);
            return;
        }

        if (e.key !== 'Tab') { return; }

        var items = focusables(overlay);
        if (!items.length) {
            e.preventDefault();
            return;
        }

        var first = items[0];
        var last = items[items.length - 1];

        if (e.shiftKey && (document.activeElement === first || !overlay.contains(document.activeElement))) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    /* ── Tabs ──────────────────────────────────────────────────────────────
       A tab's panel is #gf-tab-<name>. Panels and buttons are matched within
       the nearest [data-gf-tabs] container when there is one, so a page can
       carry two independent tab strips; without one the whole document is the
       scope, which is what the single-strip pages expect. */

    Guardify.activateTab = function (button) {
        var name = button.getAttribute('data-tab');
        if (!name) { return; }

        var scope = button.closest('[data-gf-tabs]') || document;
        var strip = button.closest('.gf-tabs') || scope;

        toArray(strip.querySelectorAll('.gf-tab')).forEach(function (tab) {
            var on = tab === button;
            tab.classList.toggle('active', on);
            if (tab.hasAttribute('role')) {
                tab.setAttribute('aria-selected', on ? 'true' : 'false');
            }
        });

        toArray(scope.querySelectorAll('.gf-tab-content')).forEach(function (panel) {
            var on = panel.id === 'gf-tab-' + name;
            panel.classList.toggle('active', on);
            panel.style.display = on ? '' : 'none';
            panel.hidden = !on;
        });

        document.dispatchEvent(new CustomEvent('gf:tabchange', { detail: { tab: name, button: button } }));
    };

    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.gf-tab[data-tab]');
        if (!tab) { return; }
        e.preventDefault();
        Guardify.activateTab(tab);
    });

    // Arrow keys move between tabs, which is what a tablist is expected to do
    // and the only way to reach a tab without a pointer once one is focused.
    document.addEventListener('keydown', function (e) {
        var tab = e.target.closest && e.target.closest('.gf-tab[data-tab]');
        if (!tab || (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft')) { return; }

        var strip = tab.closest('.gf-tabs');
        if (!strip) { return; }

        var tabs = toArray(strip.querySelectorAll('.gf-tab[data-tab]'));
        var next = tabs[tabs.indexOf(tab) + (e.key === 'ArrowRight' ? 1 : -1)];
        if (next) {
            e.preventDefault();
            next.focus();
            Guardify.activateTab(next);
        }
    });

    /* ── Copy to clipboard ─────────────────────────────────────────────── */

    Guardify.copy = function (text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        // execCommand is the only path left on a site still served over HTTP,
        // which plenty of small shops are.
        var scratch = document.createElement('textarea');
        scratch.value = text;
        scratch.setAttribute('readonly', '');
        scratch.style.position = 'fixed';
        scratch.style.left = '-9999px';
        document.body.appendChild(scratch);
        scratch.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
        document.body.removeChild(scratch);
        return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    };

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-gf-copy]');
        if (!btn) { return; }
        e.preventDefault();

        var selector = btn.getAttribute('data-gf-copy');
        var source = selector ? document.querySelector(selector)
                              : (btn.closest('.gf-copy') || document).querySelector('.gf-copy-value');
        if (!source) { return; }

        var text = 'value' in source ? source.value : source.textContent;
        var label = btn.querySelector('[data-gf-copy-label]');
        var original = label ? label.textContent : null;

        Guardify.copy(String(text).trim()).then(function () {
            btn.classList.add('is-copied');
            if (label) { label.textContent = 'কপি হয়েছে'; }
            window.setTimeout(function () {
                btn.classList.remove('is-copied');
                if (label && original !== null) { label.textContent = original; }
            }, 1800);
        }).catch(function () {
            Guardify.toast('কপি করা যায়নি — ম্যানুয়ালি সিলেক্ট করে কপি করুন।', { type: 'error' });
        });
    });

    /* ── Button loading state ──────────────────────────────────────────── */

    Guardify.setLoading = function (button, loading) {
        if (!button) { return; }
        var el = button.jquery ? button[0] : button;
        el.classList.toggle('is-loading', !!loading);
        el.disabled = !!loading;
        el.setAttribute('aria-busy', loading ? 'true' : 'false');
    };

    /* ── Dependent fields ──────────────────────────────────────────────────
       A number input that only means something when a toggle is on should say
       so. Dimming it is cheaper to read than a sentence explaining that this
       box is ignored, and cheaper than hiding it, which makes the setting look
       like it does not exist. */

    function syncDependents(root) {
        toArray((root || document).querySelectorAll('[data-gf-depends-on]')).forEach(function (el) {
            var master = document.querySelector('[name="' + el.getAttribute('data-gf-depends-on') + '"]');
            var on = master ? (master.type === 'checkbox' ? master.checked : !!master.value) : true;
            el.classList.toggle('gf-is-inactive', !on);
            el.setAttribute('aria-disabled', on ? 'false' : 'true');
        });
    }

    Guardify.syncDependents = syncDependents;
    document.addEventListener('change', function (e) {
        if (e.target.name) { syncDependents(); }
    });

    /* ── Responsive tables ─────────────────────────────────────────────────
       Stacked rows label each cell from its column header. Copying the header
       text onto every cell in the template would mean two places to keep in
       step, so it is read off the <th> at runtime instead. */

    Guardify.enhanceTables = function (root) {
        toArray((root || document).querySelectorAll('.gf-table-stack')).forEach(function (table) {
            var heads = toArray(table.querySelectorAll('thead th')).map(function (th) {
                return (th.getAttribute('data-label') || th.textContent || '').trim();
            });
            if (!heads.length) { return; }

            toArray(table.querySelectorAll('tbody tr')).forEach(function (row) {
                toArray(row.children).forEach(function (cell, i) {
                    if (cell.hasAttribute('data-label') || !heads[i]) { return; }
                    cell.setAttribute('data-label', heads[i]);
                });
            });
        });
    };

    /* ── Boot ──────────────────────────────────────────────────────────── */

    function init() {
        Guardify.enhanceTables(document);
        syncDependents(document);

        // Any overlay in the markup starts closed; leaving that to inline
        // style="display:none" means one template forgetting it flashes a
        // dialog over the page on every load.
        toArray(document.querySelectorAll('.gf-modal-overlay, .gf-drawer-overlay')).forEach(function (overlay) {
            if (!overlay.classList.contains('is-open')) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.Guardify = Guardify;
})(window, document);

/* ══ Page logic ════════════════════════════════════════════════════════════ */

(function ($) {
    'use strict';

    var data = window.guardifyData || {};
    var GF = window.Guardify;

    /* ── Connect Form ─────────────────────────────────────────────────── */

    $(document).on('submit', '#gf-connect-form', function (e) {
        e.preventDefault();

        var apiKey = $('#gf-connection-key').val().trim();

        if (!apiKey || !apiKey.match(/^gp_[a-f0-9]+$/i)) {
            showMsg('error', 'সঠিক API কী দিন। ফরম্যাট: gp_xxxx — guardify.pro/api-keys থেকে কপি করুন।');
            return;
        }

        var $btn = $('#gf-connect-btn');
        $btn.prop('disabled', true).text('যাচাই হচ্ছে...');
        hideMsg();

        $.post(data.ajaxUrl, {
            action:     'guardify_connect',
            _wpnonce:   data.nonce,
            api_key:    apiKey
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data || {};
                var planMap = { free: 'Free', starter: 'Starter', business: 'Business' };
                var planName = planMap[d.plan] || d.plan || 'Free';
                var sms = d.sms_balance !== undefined ? d.sms_balance : 0;
                var info = '✅ সফলভাবে সংযুক্ত হয়েছে!\n\n';
                info += '📦 প্ল্যান: ' + planName + '\n';
                info += '💬 SMS ব্যালেন্স: ' + sms + '\n';
                if (d.expires_at) {
                    var exp = new Date(d.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    info += '📅 মেয়াদ: ' + (days > 0 ? days + ' দিন বাকি' : 'মেয়াদ শেষ');
                }
                showMsg('success', info.replace(/\n/g, '<br>'));
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                showMsg('error', res.data || 'সংযোগ ব্যর্থ হয়েছে।');
                $btn.prop('disabled', false).text('সংযুক্ত করুন');
            }
        })
        .fail(function () {
            showMsg('error', 'সার্ভারে সংযোগ করা যায়নি।');
            $btn.prop('disabled', false).text('সংযুক্ত করুন');
        });
    });

    /* ── Disconnect ───────────────────────────────────────────────────── */

    $(document).on('click', '#gf-disconnect-btn', function () {
        if (!confirm('আপনি কি নিশ্চিত? সংযোগ বিচ্ছিন্ন করলে Guardify Pro নিষ্ক্রিয় হবে।')) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text('বিচ্ছিন্ন হচ্ছে...');

        $.post(data.ajaxUrl, {
            action:   'guardify_disconnect',
            _wpnonce: data.nonce
        })
        .done(function (res) {
            if (res.success) {
                location.reload();
            } else {
                GF.toast(res.data || 'বিচ্ছিন্ন করা যায়নি।', { type: 'error' });
                $btn.prop('disabled', false).text('সংযোগ বিচ্ছিন্ন করুন');
            }
        })
        .fail(function () {
            GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
            $btn.prop('disabled', false).text('সংযোগ বিচ্ছিন্ন করুন');
        });
    });

    /* ── Auto-check status on load ────────────────────────────────────── */

    if (data.connected) {
        $.post(data.ajaxUrl, {
            action:   'guardify_status',
            _wpnonce: data.nonce
        })
        .done(function (res) {
            if (res.success && res.data) {
                $('#gf-status-text').text(res.data.active ? 'সক্রিয়' : 'নিষ্ক্রিয়');
                if (res.data.plan) {
                    var planMap = { none: 'কোনো প্ল্যান নেই', trial: 'Trial', starter: 'Starter', business: 'Business' };
                    $('#gf-plan-text').text(planMap[res.data.plan] || res.data.plan);
                }
                if (res.data.sms_balance !== undefined) {
                    $('#gf-sms-text').text(res.data.sms_balance.toLocaleString('bn-BD'));
                }
                var $expiry = $('#gf-expiry-text').removeClass('gf-text-danger');
                if (res.data.expires_at) {
                    var exp = new Date(res.data.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    if (days > 0) {
                        // A week's warning is the point at which a merchant can
                        // still act before SMS stops going out.
                        $expiry.text(days + ' দিন বাকি').toggleClass('gf-text-danger', days <= 7);
                    } else {
                        $expiry.text('মেয়াদ শেষ').addClass('gf-text-danger');
                    }
                } else {
                    $expiry.text('কোনো মেয়াদ নেই');
                }

                // Show upgrade/renew link for trial or expired plans
                var plan = res.data.plan || 'none';
                var isExpired = res.data.expires_at && new Date(res.data.expires_at) < new Date();
                if (plan === 'trial' || plan === 'none' || isExpired) {
                    var linkText = isExpired ? 'রিনিউ করুন' : (plan === 'trial' ? 'আপগ্রেড করুন' : 'সাবস্ক্রিপশন নিন');
                    $('<a>')
                        .attr({ href: 'https://guardify.pro/subscription', target: '_blank', rel: 'noopener' })
                        .addClass('gf-text-xs')
                        .text(linkText + ' ↗')
                        .appendTo($('#gf-plan-text').append(' '));
                }
            } else {
                $('#gf-status-text').text('যাচাই ব্যর্থ');
            }
        })
        .fail(function () {
            $('#gf-status-text').text('সংযোগ ত্রুটি');
        });

        /* ── Auto-fetch Steadfast balance ─────────────────────────────── */
        if ($('#gf-steadfast-balance').length) {
            $.post(data.ajaxUrl, {
                action:   'guardify_courier_balance',
                provider: 'steadfast',
                _wpnonce: data.courierNonce
            })
            .done(function (res) {
                if (res.success && res.data) {
                    var bal = res.data.current_balance !== undefined ? res.data.current_balance : res.data.balance;
                    if (bal !== undefined) {
                        $('#gf-steadfast-balance').text('৳' + Number(bal).toLocaleString('bn-BD'));
                    } else {
                        $('#gf-steadfast-balance').text('—');
                    }
                } else {
                    $('#gf-steadfast-balance').text('ত্রুটি');
                }
            })
            .fail(function () {
                $('#gf-steadfast-balance').text('—');
            });
        }
    }

    /* ── Save bar visibility ───────────────────────────────────────────────
       Tab switching itself is handled by the component layer. Only the tabs
       that hold saveable options get the save bar; showing it on the support
       or update tab implies there is something there to save. */

    var SAVE_TABS = ['features', 'protection', 'notifications'];

    function syncSaveBar(tab) {
        var $save = $('#gf-save-wrap');
        if ($save.length) {
            $save.toggle(SAVE_TABS.indexOf(tab) !== -1);
        }
    }

    document.addEventListener('gf:tabchange', function (e) {
        syncSaveBar(e.detail.tab);
    });

    /* ── Save Settings ────────────────────────────────────────────────── */

    $(document).on('click', '#gf-save-settings', function () {
        var $btn = $(this);
        GF.setLoading($btn, true);

        var payload = {
            action:   'guardify_save_settings',
            _wpnonce: data.nonce
        };

        // Collect all toggle checkboxes
        $('.gf-setting-toggle').each(function () {
            var name = $(this).attr('name');
            if (!name) return;
            // Handle array names (e.g., guardify_notification_statuses[])
            if (name.indexOf('[]') !== -1) {
                var baseName = name.replace('[]', '');
                if (!payload[baseName]) payload[baseName] = [];
                if ($(this).is(':checked')) {
                    payload[baseName].push($(this).val());
                }
            } else {
                payload[name] = $(this).is(':checked') ? 'yes' : 'no';
            }
        });

        // Collect text/number/select inputs
        $('.gf-setting-input').each(function () {
            var name = $(this).attr('name');
            if (!name) return;
            payload[name] = $(this).val();
        });

        $.post(data.ajaxUrl, payload)
        .done(function (res) {
            if (res.success) {
                GF.toast((res.data && res.data.message) || 'সেটিংস সংরক্ষিত হয়েছে।', { type: 'success' });
            } else {
                GF.toast('সেটিংস সংরক্ষণ করা যায়নি।', { type: 'error' });
            }
        })
        .fail(function () {
            GF.toast('সার্ভারে সংযোগ করা যায়নি। আবার চেষ্টা করুন।', { type: 'error' });
        })
        .always(function () {
            GF.setLoading($btn, false);
        });
    });

    /* ── Support Ticket ──────────────────────────────────────────────── */

    // Dynamic ticket type fields
    $(document).on('change', '#gf-support-type', function () {
        var map = {
            'সমস্যা রিপোর্ট':  '.gf-field-problem',
            'ফিচার রিকোয়েস্ট': '.gf-field-feature',
            'তথ্য প্রয়োজন':   '.gf-field-info',
            'সাধারণ প্রশ্ন':    '.gf-field-general',
            'বিলিং':            '.gf-field-billing'
        };
        $('.gf-ticket-field').hide();
        var sel = map[$(this).val()];
        if (sel) $(sel).show();
    });

    $(document).on('click', '#gf-support-submit', function () {
        var ticketType = $('#gf-support-type').val();
        var whatsapp   = $('#gf-support-whatsapp').val().trim();
        var $err       = $('#gf-support-msg');

        // Get active textarea
        var message = '';
        var fieldMap = {
            'সমস্যা রিপোর্ট':  '#gf-support-problem',
            'ফিচার রিকোয়েস্ট': '#gf-support-feature',
            'তথ্য প্রয়োজন':   '#gf-support-info',
            'সাধারণ প্রশ্ন':    '#gf-support-general',
            'বিলিং':            '#gf-support-billing'
        };
        var activeField = fieldMap[ticketType];
        if (activeField) message = $(activeField).val().trim();

        function fail(text, $field) {
            $err.text(text).show();
            if ($field) { $field.addClass('is-invalid').trigger('focus'); }
        }

        $err.hide().text('');
        $('#gf-support-whatsapp, .gf-ticket-field textarea').removeClass('is-invalid');

        if (!whatsapp) {
            fail('WhatsApp নম্বর আবশ্যক — এই নম্বরেই আমরা যোগাযোগ করব।', $('#gf-support-whatsapp'));
            return;
        }
        if (!message) {
            fail('বিস্তারিত লিখুন, নাহলে আমরা সমস্যাটি বুঝতে পারব না।', activeField ? $(activeField) : null);
            return;
        }

        var $btn = $(this);
        GF.setLoading($btn, true);

        $.post(data.ajaxUrl, {
            action:      'guardify_support_ticket',
            _wpnonce:    data.nonce,
            subject:     ticketType,
            message:     message,
            whatsapp:    whatsapp,
            ticket_type: ticketType
        })
        .done(function (res) {
            if (res.success) {
                var id = res.data && res.data.ticket_id ? res.data.ticket_id : '';
                $('#gf-ticket-id-val').val(id);
                $('#gf-ticket-id-box').toggle(!!id);
                GF.openModal('#gf-ticket-popup');

                // Clear form
                $('#gf-support-whatsapp').val('');
                $('.gf-ticket-field textarea').val('');
            } else {
                fail(res.data || 'টিকেট পাঠানো যায়নি।');
            }
        })
        .fail(function () {
            fail('সার্ভারে সংযোগ করা যায়নি। আবার চেষ্টা করুন।');
        })
        .always(function () {
            GF.setLoading($btn, false);
        });
    });

    /* ── Update Check ──────────────────────────────────────────────────── */

    $(document).on('click', '#gf-check-update-btn', function () {
        var $btn = $(this);
        GF.setLoading($btn, true);

        $.post(data.ajaxUrl, {
            action:   'guardify_check_update',
            _wpnonce: data.nonce,
        })
        .done(function (res) {
            if (res.success) {
                GF.toast(res.data.message, { type: res.data.has_update ? 'warning' : 'success' });
                if (res.data.has_update) {
                    // Reload so WP update nag appears
                    setTimeout(function () { location.reload(); }, 2000);
                }
            } else {
                GF.toast(res.data || 'আপডেট চেক ব্যর্থ হয়েছে।', { type: 'error' });
            }
        })
        .fail(function () {
            GF.toast('সার্ভারে সংযোগ করা যায়নি।', { type: 'error' });
        })
        .always(function () {
            GF.setLoading($btn, false);
        });
    });

    /* ── Connection method switch ───────────────────────────────────────────
       Switching between the auto-login and the paste-a-key form clears any
       error still on screen; the error belonged to the other method. */

    document.addEventListener('gf:tabchange', function (e) {
        if (e.detail.tab === 'method-auto' || e.detail.tab === 'method-manual') {
            hideMsg();
        }
    });

    /* ── Auto-Fetch (Login & Connect) ─────────────────────────────────── */

    $(document).on('submit', '#gf-auto-fetch-form', function (e) {
        e.preventDefault();

        var email    = $('#gf-login-email').val().trim();
        var password = $('#gf-login-password').val();

        if (!email || !password) {
            showMsg('error', 'ইমেইল ও পাসওয়ার্ড দিন।');
            return;
        }

        var $btn = $('#gf-auto-fetch-btn');
        $btn.prop('disabled', true).text('লগইন হচ্ছে...');
        hideMsg();

        $.post(data.ajaxUrl, {
            action:   'guardify_auto_fetch',
            _wpnonce: data.nonce,
            email:    email,
            password: password
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data || {};
                var planMap = { free: 'Free', starter: 'Starter', business: 'Business' };
                var planName = planMap[d.plan] || d.plan || 'Free';
                var sms = d.sms_balance !== undefined ? d.sms_balance : 0;
                var info = '✅ সফলভাবে সংযুক্ত হয়েছে!\n\n';
                info += '📦 প্ল্যান: ' + planName + '\n';
                info += '💬 SMS ব্যালেন্স: ' + sms + '\n';
                if (d.expires_at) {
                    var exp = new Date(d.expires_at);
                    var now = new Date();
                    var days = Math.ceil((exp - now) / (1000 * 60 * 60 * 24));
                    info += '📅 মেয়াদ: ' + (days > 0 ? days + ' দিন বাকি' : 'মেয়াদ শেষ');
                }
                showMsg('success', info.replace(/\n/g, '<br>'));
                setTimeout(function () { location.reload(); }, 2000);
            } else {
                showMsg('error', res.data || 'সংযোগ ব্যর্থ হয়েছে।');
                $btn.prop('disabled', false).text('লগইন ও কানেক্ট');
            }
        })
        .fail(function () {
            showMsg('error', 'সার্ভারে সংযোগ করা যায়নি।');
            $btn.prop('disabled', false).text('লগইন ও কানেক্ট');
        });
    });

    /* ── Helpers ───────────────────────────────────────────────────────── */

    function showMsg(type, text) {
        var cls = type === 'success' ? 'gf-alert-success' : 'gf-alert-error';
        $('#gf-connect-msg').html('<div class="gf-alert ' + cls + '">' + escHtml(text) + '</div>').show();
    }

    function hideMsg() {
        $('#gf-connect-msg').hide().empty();
    }

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ── Phone Sync Status & Manual Trigger ───────────────────────────── */

    function loadSyncStatus() {
        $.post(data.ajaxUrl, {
            action: 'guardify_sync_status',
            _wpnonce: data.nonce
        }).done(function (res) {
            if (res.success && res.data) {
                var d = res.data;
                $('#gf-sync-total').text(d.total_orders ? d.total_orders.toLocaleString() : '—');
                $('#gf-sync-scanned').text(d.orders_scanned ? d.orders_scanned.toLocaleString() : '0');
                $('#gf-sync-sent').text(d.phones_sent ? d.phones_sent.toLocaleString() : '0');
                var pct = d.is_complete ? 100 : (d.total_orders > 0 ? Math.min(99, Math.round((d.orders_scanned / d.total_orders) * 100)) : 0);
                $('#gf-sync-progress').css('width', pct + '%')
                    .closest('.gf-progress').attr('aria-valuenow', pct);
                $('#gf-sync-pct').text(pct + '%');
                $('#gf-sync-badge').removeClass('gf-badge-muted gf-badge-success gf-badge-warning')
                    .addClass(d.is_complete ? 'gf-badge-success' : 'gf-badge-warning')
                    .text(d.is_complete ? 'সম্পন্ন' : 'চলমান — ' + pct + '%');
            }
        });
    }

    // Load sync status on page load (only on settings page)
    if ($('#gf-sync-badge').length) {
        loadSyncStatus();
    }

    // Manual sync button
    $(document).on('click', '#gf-manual-sync-btn', function () {
        var $btn = $(this);
        GF.setLoading($btn, true);
        $('#gf-sync-msg').text('');

        $.post(data.ajaxUrl, {
            action: 'guardify_manual_sync',
            _wpnonce: data.nonce,
            force: 'false'
        }).done(function (res) {
            if (res.success && res.data) {
                var d = res.data;
                var msg = d.batches_processed + ' ব্যাচ প্রসেস হয়েছে';
                if (d.is_complete) {
                    msg += ' — সিংক সম্পন্ন';
                }
                $('#gf-sync-msg').text(msg);
                loadSyncStatus();
            } else {
                GF.toast(res.data && res.data.message ? res.data.message : 'সিংক ব্যর্থ হয়েছে।', { type: 'error' });
            }
        }).fail(function () {
            GF.toast('সার্ভারে সমস্যা হয়েছে।', { type: 'error' });
        }).always(function () {
            GF.setLoading($btn, false);
        });
    });

})(jQuery);
