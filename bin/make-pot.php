#!/usr/bin/env php
<?php
/**
 * Regenerate languages/guardify-pro.pot from the source.
 *
 * WP-CLI's `wp i18n make-pot` is the usual tool for this and produces a richer catalogue,
 * but it pulls in a WordPress install and a Composer tree that nothing else in this
 * repository needs. This covers the call forms the plugin actually uses, runs on plain
 * PHP, and — because it is cheap — runs in CI on every push, which is the part that
 * matters. A .pot committed once and never regenerated is worse than no .pot: it looks
 * authoritative while describing a version of the plugin that no longer exists.
 *
 * Usage:
 *   php bin/make-pot.php           # write the catalogue
 *   php bin/make-pot.php --check   # exit 1 if the committed file is out of date
 */

$root = dirname(__DIR__);
$out  = $root . '/languages/guardify-pro.pot';

$check = in_array('--check', $argv, true);

$files = array_merge(
    glob($root . '/includes/*.php') ?: [],
    glob($root . '/templates/*.php') ?: [],
    [$root . '/guardify-pro.php']
);
sort($files);

// The gettext call forms in use. Plural forms (_n) are absent because the plugin has none;
// add them here rather than hand-editing the catalogue if that changes.
$pattern = '/\b(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'
    . '(?P<q>[\'"])(?P<text>(?:\\\\.|(?!(?P=q)).)*)(?P=q)\s*,\s*'
    . '[\'"]guardify-pro[\'"]\s*\)/';

$entries = [];
foreach ($files as $file) {
    $lines = file($file);
    if ($lines === false) {
        continue;
    }
    $relative = ltrim(str_replace($root, '', $file), '/');

    foreach ($lines as $i => $line) {
        if (!preg_match_all($pattern, $line, $matches, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($matches as $match) {
            $text = $match['text'];
            if (!isset($entries[$text])) {
                $entries[$text] = [];
            }
            $entries[$text][] = $relative . ':' . ($i + 1);
        }
    }
}

$escape = function ($string) {
    return str_replace(
        ['\\', '"', "\n", "\t"],
        ['\\\\', '\\"', '\\n', '\\t'],
        $string
    );
};

$header = <<<'POT'
# Copyright (C) Tansiq Labs
# This file is distributed under the same licence as the Guardify Pro plugin.
#
# The source strings are Bengali. That is deliberate: this plugin is built for the
# Bangladeshi market, and a merchant in Dhaka should not have to install a translation
# before the product reads correctly. A catalogue here is for correcting that Bengali
# without editing PHP, or for supplying English to an agency back office — not for making
# Bengali an afterthought.
#
# Regenerate with: php bin/make-pot.php
msgid ""
msgstr ""
"Project-Id-Version: Guardify Pro\n"
"Report-Msgid-Bugs-To: https://github.com/GuardifyPro/Guardify-Plugin/issues\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"
"Content-Transfer-Encoding: 8bit\n"
"Language-Team: Bengali (Bangladesh)\n"
"X-Domain: guardify-pro\n"

POT;

$body = '';
foreach ($entries as $text => $refs) {
    $body .= '#: ' . implode(' ', $refs) . "\n";
    $body .= 'msgid "' . $escape($text) . "\"\n";
    $body .= "msgstr \"\"\n\n";
}

$contents = $header . "\n" . $body;

if ($check) {
    $existing = is_readable($out) ? file_get_contents($out) : '';
    if ($existing !== $contents) {
        fwrite(STDERR, "languages/guardify-pro.pot is out of date.\n");
        fwrite(STDERR, "Run: php bin/make-pot.php\n");
        exit(1);
    }
    echo "languages/guardify-pro.pot is up to date (" . count($entries) . " strings).\n";
    exit(0);
}

if (!is_dir(dirname($out))) {
    mkdir(dirname($out), 0755, true);
}
file_put_contents($out, $contents);

echo 'Wrote ' . count($entries) . " strings to languages/guardify-pro.pot\n";
