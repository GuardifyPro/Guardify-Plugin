<?php
/**
 * Restore safety tests.
 *
 * A restore is the one operation in this plugin where being wrong costs the merchant their
 * whole business rather than one order. The rewriting asserted here is what stands between
 * "imported into staging tables, live site untouched" and "wrote directly over the shop",
 * and the difference is a single regular expression.
 *
 * Run: php tests/test-restore.php
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('add_action')) {
    function add_action() { return true; }
}

require_once __DIR__ . '/../includes/class-guardify-restore.php';

const GF_PREFIX = 'wp_';

/** Rewrites a statement and returns [sql, table]. */
function gf_stage($sql) {
    $table = null;
    $out   = Guardify_Restore::to_staging($sql, GF_PREFIX, $table);
    return [$out, $table];
}

// ─── Every statement that writes goes to staging ─────────────────────────────

// If any of these slipped through unrewritten, the import would run against the live
// shop — dropping its tables and then filling them in over the following minutes, with
// customers browsing throughout.
$writes = [
    'DROP TABLE IF EXISTS `wp_posts`;',
    'DROP TABLE `wp_posts`;',
    'CREATE TABLE `wp_posts` (`ID` bigint(20) NOT NULL);',
    'CREATE TABLE IF NOT EXISTS `wp_posts` (`ID` bigint(20) NOT NULL);',
    "INSERT INTO `wp_posts` (`ID`,`post_title`) VALUES ('1','hello');",
    "REPLACE INTO `wp_posts` (`ID`) VALUES ('1');",
    'ALTER TABLE `wp_posts` ADD KEY `k` (`ID`);',
];

foreach ($writes as $sql) {
    list($out, $table) = gf_stage($sql);
    gf_assert($out !== null, "rewritten: " . substr($sql, 0, 34));
    gf_assert(strpos($out, '`wp_gfr_posts`') !== false, "targets the staging table: " . substr($sql, 0, 34));
    gf_assert(strpos($out, '`wp_posts`') === false, "no longer names the live table: " . substr($sql, 0, 34));
    gf_assert_same('wp_posts', $table, "reports the original table: " . substr($sql, 0, 34));
}

// Case is not something a dump format guarantees.
list($lower) = gf_stage("insert into `wp_options` (`option_name`) values ('siteurl');");
gf_assert(strpos($lower, '`wp_gfr_options`') !== false, 'lower-case keywords are rewritten too');

// ─── Row data is never touched ───────────────────────────────────────────────

// This is the reason the match is anchored to the head of the statement. A product
// description quoting SQL, or a serialised option that happens to contain a table name, is
// ordinary content — and a blind string replacement across the statement would silently
// corrupt it while the import reported success.
$hostile = "INSERT INTO `wp_posts` (`ID`,`post_content`) VALUES "
    . "('1','See DROP TABLE `wp_users`; and INSERT INTO `wp_options` for details');";

list($out) = gf_stage($hostile);

gf_assert(strpos($out, '`wp_gfr_posts`') !== false, 'the target table is staged');
gf_assert(substr_count($out, 'wp_gfr_') === 1, 'only the statement head was rewritten');
gf_assert(strpos($out, 'DROP TABLE `wp_users`') !== false, 'table names inside row data are left alone');
gf_assert(strpos($out, 'INSERT INTO `wp_options` for details') !== false, 'row text survives unchanged');

// A dollar sign in the data would be read as a backreference by a careless replacement,
// silently eating characters out of the merchant's content.
list($dollars) = gf_stage("INSERT INTO `wp_posts` (`c`) VALUES ('price \$1 and \$0 off');");
gf_assert(strpos($dollars, '$1 and $0') !== false, 'dollar signs in row data are not treated as backreferences');

// ─── Tables outside this install are refused ─────────────────────────────────

// One database serving several WordPress installs is routine on cPanel hosting. An archive
// naming another install's tables must not be able to write to them — that is one
// merchant's restore destroying a different merchant's shop.
foreach (['wpb_posts', 'other_posts', 'mysql', 'information_schema'] as $foreign) {
    list($out, $table) = gf_stage("DROP TABLE IF EXISTS `{$foreign}`;");
    gf_assert_same(null, $out, "a statement naming `{$foreign}` is refused");
    gf_assert_same('', $table, "no table is reported for `{$foreign}`");
}

// ─── Statements that name no table ───────────────────────────────────────────

// The dump's own header. These have to pass through as "not a table statement" so the
// caller can decide, rather than being mistaken for something addressed to a table.
foreach (['SET NAMES utf8mb4;', 'SET foreign_key_checks = 0;', '-- Guardify Pro Database Backup'] as $sql) {
    list($out, $table) = gf_stage($sql);
    gf_assert_same(null, $out, "names no table: " . substr($sql, 0, 30));
    gf_assert_same('', $table, "reports no table: " . substr($sql, 0, 30));
}

// A SELECT or an UPDATE has no business in a dump this plugin produced, and running one
// from an archive would be an instruction from a file rather than from the merchant.
foreach ([
    'SELECT * FROM `wp_users`;',
    "UPDATE `wp_users` SET `user_pass` = 'x';",
    'GRANT ALL ON *.* TO `attacker`;',
    'TRUNCATE TABLE `wp_posts`;',
] as $sql) {
    list($out) = gf_stage($sql);
    gf_assert_same(null, $out, 'not executed: ' . substr($sql, 0, 30));
}

// ─── The staging name is derived, not guessed ────────────────────────────────

list(, $t) = gf_stage('CREATE TABLE `wp_woocommerce_order_items` (`x` int);');
gf_assert_same('wp_woocommerce_order_items', $t, 'long table names survive intact');

list($multi) = gf_stage('CREATE TABLE `wp_2_posts` (`x` int);');
gf_assert(strpos($multi, '`wp_gfr_2_posts`') !== false, 'a multisite table keeps its site id');

// A prefix other than wp_ has to work — plenty of installs use one for exactly this
// reason, and it is the case where a hardcoded 'wp_' would write to the wrong place.
$custom = null;
$out = Guardify_Restore::to_staging("INSERT INTO `shop7_posts` (`a`) VALUES ('x');", 'shop7_', $custom);
gf_assert(strpos($out, '`shop7_gfr_posts`') !== false, 'a custom prefix stages under itself');
gf_assert_same('shop7_posts', $custom, 'a custom prefix reports its own table');

// ...and the same statement must be refused when the site's prefix is different.
$mismatch = null;
gf_assert_same(
    null,
    Guardify_Restore::to_staging("INSERT INTO `shop7_posts` (`a`) VALUES ('x');", 'wp_', $mismatch),
    'an archive from a differently-prefixed install is not applied'
);

// ─── Budgets stay inside what shared hosting allows ──────────────────────────

gf_assert(Guardify_Restore::SLICE_BUDGET <= 20,
    'an import slice yields well inside a 30-second max_execution_time');
gf_assert(Guardify_Restore::DECOMPRESS_BUDGET <= 60,
    'decompression is bounded even though it is the stage that cannot resume cheaply');
gf_assert(Guardify_Restore::DOWNLOAD_CHUNK <= 33554432,
    'a download slice stays small enough for a modest memory_limit');

// The two infixes must differ, or the swap would rename a table onto itself and the
// merchant would lose the very copy the parking step exists to preserve.
gf_assert(
    Guardify_Restore::STAGING_INFIX !== Guardify_Restore::RETIRED_INFIX,
    'staging and retired tables are distinguishable'
);

// Neither infix may be a prefix of the other. `SHOW TABLES LIKE 'wp_gf%'` during cleanup
// would otherwise match both sets and drop the parked copy of the live database.
gf_assert(
    strpos(Guardify_Restore::STAGING_INFIX, Guardify_Restore::RETIRED_INFIX) !== 0
        && strpos(Guardify_Restore::RETIRED_INFIX, Guardify_Restore::STAGING_INFIX) !== 0,
    'neither table infix is a prefix of the other'
);

// A staging table must never be mistaken for a real one on a later run.
list($restage) = gf_stage('CREATE TABLE `wp_gfr_posts` (`x` int);');
gf_assert(
    strpos($restage, '`wp_gfr_gfr_posts`') !== false,
    'an already-staged name is staged again rather than colliding with the live swap set'
);

gf_test_summary('restore');
