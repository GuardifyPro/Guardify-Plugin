<?php
/**
 * Database dump tests.
 *
 * The backup runs on the merchant's own server, on shared hosting, while customers are
 * browsing the shop. Everything asserted here is about not being expensive or not being
 * wrong in a way that only shows up on the day someone needs to restore — the two failure
 * modes a backup feature has.
 *
 * Run: php tests/test-backup.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/class-guardify-backup.php';

// ─── Test doubles ────────────────────────────────────────────────────────────

/**
 * Minimal $wpdb that records every query it is asked to run, so the tests can assert the
 * shape of the SQL rather than its results.
 */
class GF_Fake_WPDB {
    public $prefix = 'wp_';
    public $queries = [];
    public $rows = [];   // queue of result sets returned by get_results()
    public $cols = [];   // result returned by get_col()
    public $keys = [];   // result returned for SHOW KEYS

    public function esc_like($text) {
        return addcslashes((string) $text, '_%\\');
    }

    public function prepare($query, ...$args) {
        // Enough of the real behaviour for these tests: positional %s/%d substitution with
        // quoting on strings. The point is to see what reaches MySQL, not to re-implement wpdb.
        $out = '';
        $i   = 0;
        $len = strlen($query);
        for ($p = 0; $p < $len; $p++) {
            if ($query[$p] === '%' && $p + 1 < $len && ($query[$p + 1] === 's' || $query[$p + 1] === 'd')) {
                $arg = $args[$i] ?? '';
                $out .= $query[$p + 1] === 'd' ? (string) (int) $arg : "'" . addslashes((string) $arg) . "'";
                $i++;
                $p++;
                continue;
            }
            $out .= $query[$p];
        }
        return $out;
    }

    public function get_results($query, $output = null) {
        $this->queries[] = $query;
        return array_shift($this->rows) ?: [];
    }

    public function get_col($query) {
        $this->queries[] = $query;
        return $this->cols;
    }

    public function get_row($query, $output = null) {
        $this->queries[] = $query;
        return null;
    }

    public function last_query() {
        return end($this->queries);
    }
}

/**
 * Exposes the dump internals. The class is a singleton with a private constructor, so it is
 * built without one — none of the methods under test touch instance state.
 */
class GF_Backup_Probe extends Guardify_Backup {
    public static function build() {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
    public function t_quote($v) { return $this->quote($v); }
    public function t_tables() { return $this->site_tables(); }
    public function t_skips($t) { return $this->skips_data($t); }
    public function t_filter($t) { return $this->row_filter($t); }
    public function t_pk($t) { return $this->primary_key_columns($t); }
    public function t_page($gz, array &$job) { return $this->write_page($gz, $job); }
}

function gf_new_job(array $overrides = []) {
    return array_merge([
        'path' => '', 'note' => '', 'ack' => '', 'queue' => [],
        'table' => 'wp_posts', 'pk' => ['ID'], 'cursor' => null,
        'offset' => 0, 'rows' => 0, 'total' => 1, 'started' => time(),
    ], $overrides);
}

/** Runs a page and returns the SQL text it wrote. */
function gf_capture_page(GF_Backup_Probe $b, array &$job) {
    $tmp = tempnam(sys_get_temp_dir(), 'gfbk');
    $gz  = gzopen($tmp, 'wb6');
    $b->t_page($gz, $job);
    gzclose($gz);

    $out = '';
    $in  = gzopen($tmp, 'rb');
    while (!gzeof($in)) { $out .= gzread($in, 8192); }
    gzclose($in);
    unlink($tmp);

    return $out;
}

$backup = GF_Backup_Probe::build();

// ─── Table discovery ─────────────────────────────────────────────────────────

// In a LIKE pattern the underscore is a single-character wildcard, so an unescaped `wp_%`
// also matches `wpb_orders`. Databases shared between several installs are routine on
// cPanel hosting, and there the unescaped version pulls the neighbouring shop's tables
// into this shop's archive — and overwrites them on restore.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$backup->t_tables();

// The assertion allows for repeated backslashes because prepare() escapes the escape on its
// way to MySQL, which then unescapes it back to a literal underscore.
gf_assert(
    preg_match('/\\\\+_%/', $GLOBALS['wpdb']->last_query()) === 1,
    'the table prefix is escaped for LIKE, so a neighbouring install is not swept in'
);

// ─── Primary key discovery ───────────────────────────────────────────────────

$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$GLOBALS['wpdb']->rows[] = [
    ['Column_name' => 'term_taxonomy_id', 'Seq_in_index' => '2'],
    ['Column_name' => 'object_id',        'Seq_in_index' => '1'],
];

gf_assert_same(
    ['object_id', 'term_taxonomy_id'],
    $backup->t_pk('wp_term_relationships'),
    'composite key columns come back in key order, whatever order SHOW KEYS returned them'
);

$GLOBALS['wpdb'] = new GF_Fake_WPDB();
gf_assert_same([], $backup->t_pk('wp_no_pk'), 'a table with no primary key reports none');

// ─── Keyset pagination ───────────────────────────────────────────────────────

// This is the change that matters most for load. LIMIT/OFFSET makes MySQL read and discard
// every row before the offset, so dumping a 400,000-row postmeta table costs on the order
// of 160 million row reads instead of 400,000 — which is what turns a backup into an
// outage on shared hosting.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$page = [];
for ($i = 1; $i <= 500; $i++) {
    $page[] = ['ID' => $i, 'post_title' => 'post ' . $i];
}
$GLOBALS['wpdb']->rows[] = $page;

$job = gf_new_job();
gf_capture_page($backup, $job);

$first = $GLOBALS['wpdb']->last_query();
gf_assert(stripos($first, 'OFFSET') === false, 'the first page of a keyed table does not use OFFSET');
gf_assert(stripos($first, 'ORDER BY `ID` ASC') !== false, 'rows are ordered by the primary key');
gf_assert_same(500, $job['cursor'], 'the cursor advances to the last primary key written');
gf_assert_same(500, $job['rows'], 'the row counter tracks what was written');

// Second page picks up where the first stopped, by key rather than by position.
$GLOBALS['wpdb']->rows[] = [['ID' => 501, 'post_title' => 'post 501']];
gf_capture_page($backup, $job);

$second = $GLOBALS['wpdb']->last_query();
gf_assert(stripos($second, 'OFFSET') === false, 'later pages never fall back to OFFSET');
gf_assert(strpos($second, '`ID` > ') !== false, 'later pages seek past the cursor on the index');
gf_assert(strpos($second, "'500'") !== false, 'the seek uses the cursor from the previous page');

// A page shorter than the page size means the table is finished. Reporting that here saves
// the extra round trip that discovering it with an empty page would cost — one wasted
// query per table, on every backup, forever.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$GLOBALS['wpdb']->rows[] = [['ID' => 1, 'post_title' => 'only']];
$job = gf_new_job();
gf_assert_same(0, $backup->t_page(gzopen(tempnam(sys_get_temp_dir(), 'gfbk'), 'wb6'), $job),
    'a short page reports the table as exhausted');

// ─── Composite keys fall back safely ─────────────────────────────────────────

// Keyset paging needs a single ordered column. Where there is not one, OFFSET is
// unavoidable — but it must still be ordered, or pages can repeat and skip rows silently.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$GLOBALS['wpdb']->rows[] = [['object_id' => 1, 'term_taxonomy_id' => 2]];
$job = gf_new_job(['table' => 'wp_term_relationships', 'pk' => ['object_id', 'term_taxonomy_id']]);
gf_capture_page($backup, $job);

$composite = $GLOBALS['wpdb']->last_query();
gf_assert(stripos($composite, 'ORDER BY `object_id` ASC, `term_taxonomy_id` ASC') !== false,
    'composite-key paging orders by every key column so pages cannot overlap');
gf_assert(stripos($composite, 'OFFSET') !== false, 'composite-key paging uses OFFSET, having no alternative');
gf_assert_same(1, $job['offset'], 'the offset advances by the rows actually read');

// ─── Extended inserts ────────────────────────────────────────────────────────

// One INSERT per row triples the size of the dump and makes the import — the part a
// merchant sits and waits through during an emergency — an order of magnitude slower.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$rows = [];
for ($i = 1; $i <= 50; $i++) {
    $rows[] = ['ID' => $i, 'post_title' => 'row ' . $i];
}
$GLOBALS['wpdb']->rows[] = $rows;

$job = gf_new_job();
$sql = gf_capture_page($backup, $job);

gf_assert_same(1, substr_count($sql, 'INSERT INTO'), '50 rows are written as one extended INSERT');
gf_assert(substr_count($sql, "('1','row 1')") === 1, 'each row appears once');
gf_assert(substr_count($sql, "('50','row 50')") === 1, 'the last row of the page is not dropped');

// ─── Value quoting ───────────────────────────────────────────────────────────

gf_assert_same('NULL', $backup->t_quote(null), 'NULL is a keyword, not the string "NULL"');
gf_assert_same("'plain'", $backup->t_quote('plain'), 'ordinary text is quoted');
gf_assert_same("'it\\'s'", $backup->t_quote("it's"), 'a quote in the data does not end the literal early');

// Binary data — a serialised object holding raw bytes, an image in a meta field — is not
// valid UTF-8. Escaping it as text corrupts it silently: the dump imports without error and
// the data comes back wrong, which is worse than a backup that visibly failed.
$binary = "\xff\xfe\x00\x01";
gf_assert_same('0x' . bin2hex($binary), $backup->t_quote($binary), 'invalid UTF-8 is written as a hex literal');
gf_assert(strpos($backup->t_quote($binary), "'") === false, 'a hex literal is not wrapped in quotes');

// A multi-byte Bengali string is valid UTF-8 and must not be mistaken for binary, or every
// product title in the catalogue would be dumped as unreadable hex.
gf_assert_same("'ঢাকা'", $backup->t_quote('ঢাকা'), 'Bengali text is treated as text, not binary');

// ─── What is left out ────────────────────────────────────────────────────────

$GLOBALS['wpdb'] = new GF_Fake_WPDB();

gf_assert($backup->t_skips('wp_woocommerce_sessions'), 'live carts are not worth archiving');
gf_assert($backup->t_skips('wp_actionscheduler_logs'), 'the job log is not job state');
gf_assert(!$backup->t_skips('wp_posts'), 'posts are always dumped');
gf_assert(!$backup->t_skips('wp_woocommerce_order_items'), 'order data is always dumped');
gf_assert(!$backup->t_skips('wp_options'), 'options are always dumped — the site does not boot without them');

// wp_options cannot be skipped, but the transients inside it are a cache with an expiry
// stamped on it, and on a large catalogue they are routinely most of the table.
$filter = $backup->t_filter('wp_options');
gf_assert($filter !== '', 'wp_options has a row filter');
// The underscores are LIKE-escaped in the clause itself, so `_transient_` never appears
// unescaped — searching for it naively is how this assertion passes while the clause is wrong.
gf_assert(strpos($filter, '\\_transient\\_%') !== false, 'transients are excluded from wp_options');
gf_assert(strpos($filter, '\\_site\\_transient\\_%') !== false, 'site transients are excluded too');
gf_assert_same('', $backup->t_filter('wp_posts'), 'tables without a filter dump every row');

// The filter has to reach the query, not just exist.
$GLOBALS['wpdb'] = new GF_Fake_WPDB();
$GLOBALS['wpdb']->rows[] = [['option_id' => 1, 'option_name' => 'siteurl']];
$job = gf_new_job(['table' => 'wp_options', 'pk' => ['option_id']]);
gf_capture_page($backup, $job);
gf_assert(strpos($GLOBALS['wpdb']->last_query(), 'transient') !== false,
    'the row filter is applied on the first page');

$GLOBALS['wpdb']->rows[] = [['option_id' => 2, 'option_name' => 'home']];
gf_capture_page($backup, $job);
$filtered = $GLOBALS['wpdb']->last_query();
gf_assert(strpos($filtered, 'transient') !== false, 'the row filter survives onto later pages');
gf_assert(strpos($filtered, '`option_id` > ') !== false, 'the filter is combined with the cursor, not substituted for it');

// ─── Cost settings ───────────────────────────────────────────────────────────

// Level 9 costs several times the CPU of level 6 for a low single-digit saving in size.
// That CPU comes out of the merchant's hosting quota; the bytes come out of Guardify's
// storage bill, which is the side that can afford them.
$source = file_get_contents(__DIR__ . '/../includes/class-guardify-backup.php');
gf_assert(strpos($source, "'wb9'") === false, 'the dump does not compress at maximum CPU');
gf_assert(strpos($source, "'wb6'") !== false, 'the dump compresses at level 6');

gf_assert(
    Guardify_Backup::SLICE_BUDGET <= 20,
    'a slice yields well inside the 30-second max_execution_time typical of shared hosting'
);
gf_assert(
    Guardify_Backup::PAGE_SIZE <= 1000,
    'a page of rows stays small enough not to dominate a low memory_limit'
);

gf_test_summary('backup');
