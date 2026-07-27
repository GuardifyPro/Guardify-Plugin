<?php
/**
 * Translation-call hygiene.
 *
 * These are structural checks on the source rather than tests of behaviour, and each one
 * exists because the corresponding mistake was actually made while wrapping ~900 strings.
 *
 * Run: php tests/test-i18n.php
 */

require_once __DIR__ . '/bootstrap.php';

$gf_root  = dirname(__DIR__);
$gf_files = array_merge(
    [$gf_root . '/guardify-pro.php'],
    glob($gf_root . '/includes/*.php'),
    glob($gf_root . '/templates/*.php')
);

/** Strip PHP blocks from a chunk, leaving what the browser would receive. */
function gf_strip_php($chunk) {
    $chunk = preg_replace('/<\?php.*?\?>/s', '', $chunk);
    return preg_replace('/<\?=.*?\?>/s', '', $chunk);
}

/**
 * Strip JavaScript comments.
 *
 * The bundle preamble explains, in a comment, that inline JavaScript cannot call __() — and
 * without this the check would read its own explanation as the offence it is describing.
 */
function gf_strip_js_comments($js) {
    $js = preg_replace('#/\*.*?\*/#s', '', $js);
    return preg_replace('#^\s*//.*$#m', '', $js);
}

/** Every <script> element in a file, PHP removed — i.e. the JavaScript itself. */
function gf_script_bodies($source) {
    if (!preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $m)) {
        return [];
    }
    return array_map(function ($gf_block) {
        return gf_strip_js_comments(gf_strip_php($gf_block));
    }, $m[1]);
}

// ─── PHP calls must not end up inside JavaScript ─────────────────────────────

// The mistake: a pattern-driven pass wrapped `showMsg('error', 'বাংলা')` without noticing it
// was inside a <script> block. `__` is not a JavaScript function, so the page's whole script
// throws on the first call — and nothing in a PHP lint or a unit test would have said so.
//
// Translated strings for inline scripts go through the GF_I18N object that PHP builds
// instead. This check is what keeps the next mechanical pass from undoing that.
foreach ($gf_files as $gf_file) {
    $gf_name = basename($gf_file);
    foreach (gf_script_bodies(file_get_contents($gf_file)) as $gf_i => $gf_js) {
        gf_assert(
            !preg_match('/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\s*\(/', $gf_js),
            "no PHP translation call inside JavaScript: {$gf_name} script #{$gf_i}"
        );
    }
}

// ─── Translations must not be resolved at class-load time ────────────────────

// A const or a property initialiser is evaluated while the file is being read, which happens
// on `plugins_loaded`. The text domain is loaded on `init`. A label captured in between
// resolves before any catalogue exists and comes out in the source language no matter what
// the site installed — a failure with no error and no obvious cause.
foreach ($gf_files as $gf_file) {
    $gf_name  = basename($gf_file);
    $gf_lines = file($gf_file);
    foreach ($gf_lines as $gf_no => $gf_line) {
        if (strpos($gf_line, '__(') === false) {
            continue;
        }
        $gf_trimmed = ltrim($gf_line);
        $gf_is_load_time = preg_match('/^const\s/', $gf_trimmed)
            || preg_match('/^(?:public|private|protected)\s+(?:static\s+)?\$/', $gf_trimmed);

        gf_assert(
            !$gf_is_load_time,
            "translation is not resolved at class-load time: {$gf_name}:" . ($gf_no + 1)
        );
    }
}

// ─── Every translation call names this plugin's text domain ──────────────────

// A call with a missing or wrong domain is not an error. It silently looks up the string in
// somebody else's catalogue, finds nothing, and returns the source text — so it works
// perfectly in Bengali and is simply never translatable, which is the one outcome this whole
// exercise was meant to prevent.
//
// 'woocommerce' is allowed for strings that are WooCommerce's, not ours: the repeat blocker
// has to find the checkout button by the same label WooCommerce gave it, so it must look that
// label up in WooCommerce's catalogue. Putting it in ours would find nothing and the button
// would go unmatched on every non-Bengali site.
$gf_foreign_domains = ['woocommerce'];
$gf_domain_misses = 0;
foreach ($gf_files as $gf_file) {
    $gf_src = file_get_contents($gf_file);

    if (!preg_match_all(
        '/\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*(?:\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*(,\s*\'([^\']*)\')?/',
        $gf_src,
        $gf_matches,
        PREG_SET_ORDER
    )) {
        continue;
    }

    foreach ($gf_matches as $gf_match) {
        $gf_given  = isset($gf_match[2]) ? $gf_match[2] : '';
        $gf_string = $gf_match[0];

        if ($gf_given === 'guardify-pro') {
            continue;
        }

        // A Bengali string is unambiguously ours, whatever domain was typed.
        $gf_is_ours = (bool) preg_match('/[\x{0985}-\x{09B9}\x{09CE}]/u', $gf_string);

        if (!$gf_is_ours && in_array($gf_given, $gf_foreign_domains, true)) {
            continue;
        }

        $gf_domain_misses++;
    }
}
gf_assert_same(0, $gf_domain_misses, 'every translation call names the guardify-pro text domain');

// ─── The catalogue is not empty, and covers the product ──────────────────────

$gf_pot = $gf_root . '/languages/guardify-pro.pot';
gf_assert(file_exists($gf_pot), 'the translation catalogue exists');

$gf_pot_src   = file_get_contents($gf_pot);
$gf_msgid_num = substr_count($gf_pot_src, "\nmsgid ");

// A number rather than a range, because the failure this guards against is a pass that
// silently stops finding strings — a catalogue that shrank from hundreds to a handful still
// looks like a valid .pot file.
gf_assert(
    $gf_msgid_num > 500,
    "the catalogue covers the product ({$gf_msgid_num} strings)"
);

// ─── Developer output stays in one language ──────────────────────────────────

// error_log() is read by whoever is debugging a merchant's site, and it should say the same
// thing in every locale. Translating it means a support engineer cannot search for the
// message they were sent.
foreach ($gf_files as $gf_file) {
    $gf_src = file_get_contents($gf_file);
    gf_assert(
        !preg_match('/error_log\(\s*(?:__|esc_html__)\(/', $gf_src),
        'error_log output is not translated: ' . basename($gf_file)
    );
}

// ─── Inline scripts that carry Bengali have a bundle to read it from ─────────

// The bundle is what makes those strings translatable at all. A script holding Bengali with
// no GF_I18N above it means a pass added new strings the catalogue will never see.
foreach ($gf_files as $gf_file) {
    $gf_name = basename($gf_file);
    if ($gf_name === 'design-system-page.php') {
        continue; // registers only under WP_DEBUG; no merchant sees it
    }

    $gf_src = file_get_contents($gf_file);
    if (!preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $gf_src, $gf_m)) {
        continue;
    }

    foreach ($gf_m[1] as $gf_i => $gf_block) {
        $gf_js = gf_strip_js_comments(gf_strip_php($gf_block));
        // Bengali letters, not merely Bengali codepoints: the digit map in bnDigits() is
        // data the code computes with, not language.
        if (!preg_match('/[\x{0985}-\x{09B9}\x{09CE}]/u', $gf_js)) {
            continue;
        }
        gf_assert(
            strpos($gf_block, 'GF_I18N') !== false,
            "script carrying Bengali has a translation bundle: {$gf_name} script #{$gf_i}"
        );
    }
}

gf_test_summary('i18n');
