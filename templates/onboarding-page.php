<?php
/**
 * Guardify Pro — first-run setup wizard.
 *
 * Four steps, one screen. The stepper stays visible so a merchant always knows how much is
 * left, and every step can be completed without reading anything below the fold.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$gf_api       = new Guardify_API();
$gf_connected = $gf_api->is_connected();
$gf_presets   = Guardify_Onboarding::presets();
$gf_nonce     = wp_create_nonce('guardify_nonce');

$gf_steps = [
    ['key' => 'connect', 'label' => 'সংযোগ',   'desc' => 'API কী'],
    ['key' => 'protect', 'label' => 'সুরক্ষা',  'desc' => 'লেভেল বাছাই'],
    ['key' => 'backup',  'label' => 'ব্যাকআপ',  'desc' => 'দৈনিক কপি'],
    ['key' => 'scan',    'label' => 'ফলাফল',   'desc' => 'পুরোনো অর্ডার'],
];
?>

<div class="wrap gf-wrap gf-onboarding">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title">Guardify সেটআপ</h1>
                <p class="gf-page-desc">তিন মিনিটে আপনার দোকান সুরক্ষিত করুন। সেটআপ চলাকালীন কোনো অর্ডার বাতিল হবে না।</p>
            </div>
        </div>
    </div>

    <!-- Stepper -->
    <div class="gf-card gf-mb-3">
        <div class="gf-card-body">
            <div class="gf-stepper" id="gf-ob-stepper">
                <?php foreach ($gf_steps as $i => $step) : ?>
                <div class="gf-step<?php echo $i === 0 ? ' is-current' : ''; ?>" data-step="<?php echo esc_attr($step['key']); ?>">
                    <div class="gf-step-marker"><?php echo esc_html(Guardify_Format::count($i + 1)); ?></div>
                    <div class="gf-step-body">
                        <span class="gf-step-label"><?php echo esc_html($step['label']); ?></span>
                        <span class="gf-step-desc"><?php echo esc_html($step['desc']); ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ── Step 1: connect ─────────────────────────────────────────── -->
    <div class="gf-card gf-ob-panel" data-panel="connect">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">সাইট সংযুক্ত করুন</h2>
                <p class="gf-card-desc">
                    Guardify ড্যাশবোর্ড থেকে API কী কপি করে এখানে বসান। কী না থাকলে বিনামূল্যে একটি অ্যাকাউন্ট খুলে নিন।
                </p>
            </div>
        </div>
        <div class="gf-card-body">
            <?php if ($gf_connected) : ?>
            <div class="gf-alert gf-alert-success gf-mb-2">
                <span class="gf-alert-title">এই সাইট ইতিমধ্যে সংযুক্ত আছে।</span>
                নতুন কী বসালে আগেরটি বাতিল হয়ে যাবে।
            </div>
            <?php endif; ?>

            <div class="gf-field gf-field-wide">
                <label class="gf-label" for="gf-ob-key">API কী</label>
                <input type="text" id="gf-ob-key" class="gf-input gf-input-mono" placeholder="gfk_..." autocomplete="off" spellcheck="false">
                <span class="gf-help">
                    <a href="https://guardify.pro/register" target="_blank" rel="noopener">guardify.pro</a> — অ্যাকাউন্ট খুলে API কী তৈরি করুন।
                </span>
            </div>

            <div id="gf-ob-connect-msg" class="gf-mb-2"></div>

            <div class="gf-row">
                <button type="button" class="gf-btn gf-btn-primary" id="gf-ob-connect">সংযুক্ত করুন</button>
                <?php if ($gf_connected) : ?>
                <button type="button" class="gf-btn gf-btn-ghost gf-ob-next">এই কী রেখেই এগিয়ে যান</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Step 2: protection level ────────────────────────────────── -->
    <div class="gf-card gf-ob-panel gf-hidden" data-panel="protect">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">সুরক্ষার মাত্রা</h2>
                <p class="gf-card-desc">
                    একটি বেছে নিন — বাকি সব সেটিংস আমরা ঠিক করে দেব। পরে সেটিংস পেজ থেকে যেকোনো কিছু বদলাতে পারবেন।
                </p>
            </div>
        </div>
        <div class="gf-card-body">
            <div class="gf-ob-presets">
                <?php foreach ($gf_presets as $key => $preset) : ?>
                <label class="gf-ob-preset<?php echo $key === 'balanced' ? ' is-selected' : ''; ?>" data-preset="<?php echo esc_attr($key); ?>">
                    <input type="radio" name="gf_ob_preset" value="<?php echo esc_attr($key); ?>" <?php checked($key, 'balanced'); ?>>
                    <span class="gf-ob-preset-head">
                        <span class="gf-ob-preset-name"><?php echo esc_html($preset['label']); ?></span>
                        <?php if ($key === 'balanced') : ?>
                        <span class="gf-badge gf-badge-primary">সুপারিশ</span>
                        <?php endif; ?>
                    </span>
                    <span class="gf-ob-preset-summary"><?php echo esc_html($preset['summary']); ?></span>
                    <span class="gf-ob-preset-for"><?php echo esc_html($preset['best_for']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div class="gf-alert gf-alert-info gf-mt-3">
                <span class="gf-alert-title">শুরুতে কোনো অর্ডার বাতিল হবে না।</span>
                তিনটি লেভেলেই Guardify প্রথমে শুধু দেখে ও রিপোর্ট করে। কয়েক দিন ফলাফল দেখে আপনি নিজে
                সিদ্ধান্ত নিতে পারবেন কোন অর্ডার আটকাতে চান।
            </div>

            <div id="gf-ob-preset-msg" class="gf-mt-2"></div>

            <div class="gf-row gf-mt-3">
                <button type="button" class="gf-btn gf-btn-primary" id="gf-ob-preset-save">এগিয়ে যান</button>
            </div>
        </div>
    </div>

    <!-- ── Step 3: backup ──────────────────────────────────────────── -->
    <div class="gf-card gf-ob-panel gf-hidden" data-panel="backup">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">দৈনিক ব্যাকআপ</h2>
                <p class="gf-card-desc">
                    আপনার ডাটাবেইজের একটি কপি প্রতিদিন Guardify সার্ভারে রাখা হবে। ব্যাকআপ ধাপে ধাপে তৈরি হয়,
                    তাই আপনার সাইট ধীর হবে না।
                </p>
            </div>
        </div>
        <div class="gf-card-body">
            <label class="gf-check-row">
                <span class="gf-switch">
                    <input type="checkbox" id="gf-ob-backup" checked>
                    <span class="gf-switch-slider"></span>
                </span>
                <span>
                    <strong>প্রতিদিন রাত ৪টায় স্বয়ংক্রিয় ব্যাকআপ নিন</strong>
                    <span class="gf-help">দোকান তখন সবচেয়ে কম ব্যস্ত থাকে। যেকোনো সময় বন্ধ বা পরিবর্তন করতে পারবেন।</span>
                </span>
            </label>

            <div id="gf-ob-backup-msg" class="gf-mt-2"></div>

            <div class="gf-row gf-mt-3">
                <button type="button" class="gf-btn gf-btn-primary" id="gf-ob-backup-save">এগিয়ে যান</button>
            </div>
        </div>
    </div>

    <!-- ── Step 4: retroactive scan ────────────────────────────────── -->
    <div class="gf-card gf-ob-panel gf-hidden" data-panel="scan">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title">আপনার পুরোনো অর্ডার যাচাই</h2>
                <p class="gf-card-desc">
                    এখনো ডেলিভারি হয়নি এমন সাম্প্রতিক অর্ডারগুলো Guardify দেখে নিচ্ছে। এতে কোনো অর্ডার বদলাবে না —
                    শুধু কোনটিতে ঝুঁকি আছে তা দেখাবে।
                </p>
            </div>
        </div>
        <div class="gf-card-body">
            <div id="gf-ob-scan-progress">
                <div class="gf-progress">
                    <div class="gf-progress-fill" id="gf-ob-scan-bar" style="width:0%"></div>
                </div>
                <p class="gf-progress-label" id="gf-ob-scan-label">শুরু হচ্ছে…</p>
            </div>

            <div id="gf-ob-scan-result" class="gf-hidden"></div>

            <div class="gf-row gf-mt-3">
                <button type="button" class="gf-btn gf-btn-primary gf-hidden" id="gf-ob-finish">সেটআপ শেষ করুন</button>
            </div>
        </div>
    </div>

    <p class="gf-help gf-mt-3">
        <a href="#" id="gf-ob-skip">সেটআপ বাদ দিয়ে সরাসরি সেটিংসে যান</a>
    </p>
</div>

<style>
/* Wizard-only layout. Everything visual here reuses the design-system tokens; only the
   arrangement of the preset cards is specific to this screen. */
.gf-onboarding .gf-ob-presets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 0.75rem;
}
.gf-onboarding .gf-ob-preset {
    display: flex;
    flex-direction: column;
    gap: 0.375rem;
    padding: 1rem;
    border: 1.5px solid var(--gf-border);
    border-radius: var(--gf-radius-lg, 0.75rem);
    background: var(--gf-card);
    cursor: pointer;
    transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.gf-onboarding .gf-ob-preset:hover { border-color: var(--gf-primary); }
.gf-onboarding .gf-ob-preset.is-selected {
    border-color: var(--gf-primary);
    box-shadow: 0 0 0 3px oklch(var(--gf-primary-c) / 0.12);
}
/* The radio is the accessible control and stays focusable; it is simply not the thing the
   merchant clicks, because the whole card is a much larger target on a phone. */
.gf-onboarding .gf-ob-preset input { position: absolute; opacity: 0; pointer-events: none; }
.gf-onboarding .gf-ob-preset:focus-within { border-color: var(--gf-primary); }
.gf-onboarding .gf-ob-preset-head {
    display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;
}
.gf-onboarding .gf-ob-preset-name { font-weight: 650; color: var(--gf-fg); }
.gf-onboarding .gf-ob-preset-summary { font-size: 0.8125rem; color: var(--gf-fg); opacity: 0.85; line-height: 1.5; }
.gf-onboarding .gf-ob-preset-for { font-size: 0.75rem; color: var(--gf-muted-fg); }

.gf-onboarding .gf-ob-findings { width: 100%; border-collapse: collapse; margin-top: 0.75rem; }
.gf-onboarding .gf-ob-findings th,
.gf-onboarding .gf-ob-findings td {
    padding: 0.5rem 0.75rem; text-align: left; font-size: 0.8125rem;
    border-bottom: 1px solid var(--gf-border);
}
.gf-onboarding .gf-ob-findings th { color: var(--gf-muted-fg); font-weight: 550; }
</style>

<script>
jQuery(function ($) {
    var ajaxUrl = ajaxurl;
    var nonce   = <?php echo wp_json_encode($gf_nonce); ?>;
    var GF      = window.Guardify || {};

    var order = ['connect', 'protect', 'backup', 'scan'];

    function show(step) {
        $('.gf-ob-panel').addClass('gf-hidden');
        $('.gf-ob-panel[data-panel="' + step + '"]').removeClass('gf-hidden');

        var idx = order.indexOf(step);
        $('#gf-ob-stepper .gf-step').each(function (i) {
            $(this)
                .toggleClass('is-done', i < idx)
                .toggleClass('is-current', i === idx);
        });

        if (step === 'scan') { runScan(); }
    }

    function next(from) {
        var idx = order.indexOf(from);
        if (idx > -1 && idx < order.length - 1) { show(order[idx + 1]); }
    }

    function notice($el, type, text) {
        $el.html($('<div>').addClass('gf-alert gf-alert-' + type).text(text));
    }

    /* ── Step 1 ─────────────────────────────────────────────────── */

    $('#gf-ob-connect').on('click', function () {
        var $btn = $(this);
        var key  = $.trim($('#gf-ob-key').val());
        var $msg = $('#gf-ob-connect-msg').empty();

        if (!key) {
            notice($msg, 'error', 'API কী দিন।');
            return;
        }

        if (GF.setLoading) { GF.setLoading($btn, true); }

        $.post(ajaxUrl, { action: 'guardify_onboarding_connect', api_key: key, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    notice($msg, 'error', res.data || 'সংযোগ ব্যর্থ হয়েছে।');
                    return;
                }
                notice($msg, 'success', res.data.message + (res.data.plan ? ' প্ল্যান: ' + res.data.plan : ''));
                setTimeout(function () { next('connect'); }, 600);
            })
            .fail(function () { notice($msg, 'error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।'); })
            .always(function () { if (GF.setLoading) { GF.setLoading($btn, false); } });
    });

    $('.gf-ob-next').on('click', function () { next('connect'); });

    /* ── Step 2 ─────────────────────────────────────────────────── */

    $('.gf-ob-preset').on('click', function () {
        $('.gf-ob-preset').removeClass('is-selected');
        $(this).addClass('is-selected').find('input').prop('checked', true);
    });

    $('#gf-ob-preset-save').on('click', function () {
        var $btn = $(this);
        var $msg = $('#gf-ob-preset-msg').empty();
        var preset = $('input[name="gf_ob_preset"]:checked').val() || 'balanced';

        if (GF.setLoading) { GF.setLoading($btn, true); }

        $.post(ajaxUrl, { action: 'guardify_onboarding_preset', preset: preset, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { notice($msg, 'error', res.data || 'সেভ ব্যর্থ হয়েছে।'); return; }
                next('protect');
            })
            .fail(function () { notice($msg, 'error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।'); })
            .always(function () { if (GF.setLoading) { GF.setLoading($btn, false); } });
    });

    /* ── Step 3 ─────────────────────────────────────────────────── */

    $('#gf-ob-backup-save').on('click', function () {
        var $btn = $(this);
        var $msg = $('#gf-ob-backup-msg').empty();
        var on   = $('#gf-ob-backup').is(':checked') ? 'yes' : 'no';

        if (GF.setLoading) { GF.setLoading($btn, true); }

        $.post(ajaxUrl, { action: 'guardify_onboarding_backup', enabled: on, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { notice($msg, 'error', res.data || 'সেভ ব্যর্থ হয়েছে।'); return; }
                next('backup');
            })
            .fail(function () { notice($msg, 'error', 'সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।'); })
            .always(function () { if (GF.setLoading) { GF.setLoading($btn, false); } });
    });

    /* ── Step 4 ─────────────────────────────────────────────────── */

    var scanned = 0, findings = [], atRisk = 0, started = false;
    var LIMIT = <?php echo (int) Guardify_Onboarding::SCAN_LIMIT; ?>;

    function runScan() {
        if (started) { return; }
        started = true;
        scanChunk(0);
    }

    function scanChunk(offset) {
        $.post(ajaxUrl, { action: 'guardify_onboarding_scan', offset: offset, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { finishScan(res.data); return; }

                scanned += res.data.scanned || 0;
                atRisk  += res.data.value || 0;
                findings = findings.concat(res.data.risky || []);

                var pct = Math.min(100, Math.round((scanned / LIMIT) * 100));
                $('#gf-ob-scan-bar').css('width', pct + '%');
                $('#gf-ob-scan-label').text(scanned + ' টি অর্ডার দেখা হয়েছে…');

                if (res.data.done) { finishScan(null); return; }
                scanChunk(offset + (res.data.scanned || 1));
            })
            // A scan failure is not a setup failure. The merchant is already connected and
            // protected by this point, so the flow completes with an honest note rather
            // than stranding them on a broken final step.
            .fail(function () { finishScan('স্ক্যান শেষ করা যায়নি।'); });
    }

    function finishScan(error) {
        $('#gf-ob-scan-progress').addClass('gf-hidden');
        $('#gf-ob-finish').removeClass('gf-hidden');

        var $out = $('#gf-ob-scan-result').removeClass('gf-hidden').empty();

        if (error) {
            $out.append($('<div>').addClass('gf-alert gf-alert-warning').text(
                error + ' সেটআপ সম্পূর্ণ হয়েছে — Guardify এখন থেকে নতুন অর্ডার দেখবে।'
            ));
            return;
        }

        if (!findings.length) {
            $out.append($('<div>').addClass('gf-alert gf-alert-success').append(
                $('<span>').addClass('gf-alert-title').text('ভালো খবর।'),
                document.createTextNode(' আপনার সাম্প্রতিক ' + scanned +
                    ' টি অর্ডারের কোনোটিতেই উদ্বেগের মতো কিছু পাওয়া যায়নি।')
            ));
            return;
        }

        $out.append($('<div>').addClass('gf-alert gf-alert-warning').append(
            $('<span>').addClass('gf-alert-title').text(
                scanned + ' টি অর্ডারের মধ্যে ' + findings.length + ' টিতে ঝুঁকি পাওয়া গেছে।'
            ),
            document.createTextNode(' মোট মূল্য ৳' + Math.round(atRisk).toLocaleString('en-IN') +
                '। এগুলো বাতিল করা হয়নি — পাঠানোর আগে একবার দেখে নিন।')
        ));

        var $table = $('<table>').addClass('gf-ob-findings').append(
            $('<thead>').append($('<tr>').append(
                $('<th>').text('অর্ডার'),
                $('<th>').text('কাস্টমার'),
                $('<th>').text('ফোন'),
                $('<th>').text('মূল্য'),
                $('<th>').text('স্কোর')
            ))
        );
        var $body = $('<tbody>');

        // Capped at ten rows. The finding is the number; a wall of a hundred rows inside a
        // setup wizard is something a merchant scrolls past rather than reads.
        $.each(findings.slice(0, 10), function (_, f) {
            $body.append($('<tr>').append(
                $('<td>').append($('<a>').attr('href', f.url).text('#' + f.number)),
                $('<td>').text($.trim(f.name) || '—'),
                $('<td>').text(f.phone),
                $('<td>').text('৳' + Math.round(f.total).toLocaleString('en-IN')),
                $('<td>').append($('<span>').addClass('gf-badge gf-badge-danger').text(f.score === null ? '—' : f.score))
            ));
        });

        $table.append($body);
        $out.append($table);

        if (findings.length > 10) {
            $out.append($('<p>').addClass('gf-help').text(
                'আরও ' + (findings.length - 10) + ' টি অর্ডার আছে — অর্ডার তালিকায় কুরিয়ার রিপোর্ট কলামে সব দেখতে পাবেন।'
            ));
        }
    }

    $('#gf-ob-finish').on('click', function () {
        var $btn = $(this);
        if (GF.setLoading) { GF.setLoading($btn, true); }

        $.post(ajaxUrl, { action: 'guardify_onboarding_finish', _ajax_nonce: nonce })
            .done(function (res) {
                window.location.href = (res.success && res.data.redirect)
                    ? res.data.redirect
                    : <?php echo wp_json_encode(admin_url('admin.php?page=guardify-pro')); ?>;
            })
            .fail(function () { if (GF.setLoading) { GF.setLoading($btn, false); } });
    });

    $('#gf-ob-skip').on('click', function (e) {
        e.preventDefault();
        $.post(ajaxUrl, { action: 'guardify_onboarding_finish', _ajax_nonce: nonce })
            .always(function () {
                window.location.href = <?php echo wp_json_encode(admin_url('admin.php?page=guardify-pro')); ?>;
            });
    });

    show(<?php echo $gf_connected ? "'protect'" : "'connect'"; ?>);
});
</script>
