<?php
/**
 * Media sync tests.
 *
 * Two things in this class can destroy a site rather than merely fail, and both are here:
 *
 *  1. safe_rel_path() decides what a restore is allowed to write. Every path it approves
 *     becomes a filesystem write inside wp-content/uploads — a directory the web server
 *     serves, and on most cPanel hosting a directory where a .php file executes. A path that
 *     escapes it overwrites wp-config.php; a path that ends in .php is a shell.
 *
 *  2. is_excluded() decides what is not backed up. Over-matching here does not error, it
 *     silently omits a merchant's product photographs from their backup, and they find out
 *     during a restore.
 *
 * Run: php tests/test-media.php
 */

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('add_action')) {
    function add_action() { return true; }
}
if (!function_exists('add_filter')) {
    function add_filter() { return true; }
}

require_once __DIR__ . '/../includes/class-guardify-media.php';

class GF_Media_Probe extends Guardify_Media {
    public static function build() {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }
    public function t_safe($rel) { return $this->safe_rel_path($rel); }
    public function t_allowed($rel) { return $this->allowed_extension($rel); }
    public function t_excluded($rel, $dirs) { return $this->is_excluded($rel, $dirs); }
    public function t_dir_excluded($rel, $dirs) { return $this->dir_excluded($rel, $dirs); }
    public function t_content_type($path) { return $this->content_type($path); }
    public function t_absolute($base, $rel) { return $this->absolute_path($base, $rel); }
}

$m = GF_Media_Probe::build();

// ─── What a real uploads folder holds ────────────────────────────────────────

// These must all survive. A Bengali shop's media library is full of Bengali filenames, taka
// signs and spaces, and refusing them means the images are quietly never backed up while the
// sync reports success.
$accepted = [
    '2026/03/saree-red.jpg'           => '2026/03/saree-red.jpg',
    '2026/03/saree-red-300x300.jpg'   => '2026/03/saree-red-300x300.jpg',
    '2026/03/শাড়ি-লাল.jpg'           => '2026/03/শাড়ি-লাল.jpg',
    '2026/03/দাম-৳১২০০.png'           => '2026/03/দাম-৳১২০০.png',
    '2026/03/photo with spaces.jpeg'  => '2026/03/photo with spaces.jpeg',
    '2026/03/a+b&c=d.png'             => '2026/03/a+b&c=d.png',
    '2026/03/name.with.many.dots.jpg' => '2026/03/name.with.many.dots.jpg',
    '2026/03/UPPERCASE.JPG'           => '2026/03/UPPERCASE.JPG',
    'sites/3/2026/03/multisite.jpg'   => 'sites/3/2026/03/multisite.jpg',
    'woocommerce-placeholder.png'     => 'woocommerce-placeholder.png',
    '2026/03/catalogue.pdf'           => '2026/03/catalogue.pdf',
    // Leading slashes and Windows separators are tidied rather than refused: both turn up in
    // paths built by other plugins, and both mean the same file.
    '/2026/03/leading.png'            => '2026/03/leading.png',
    '2026\\03\\windows.jpg'           => '2026/03/windows.jpg',
];

foreach ($accepted as $raw => $want) {
    gf_assert_same($want, $m->t_safe($raw), "accepts: {$raw}");
}

// ─── Traversal ───────────────────────────────────────────────────────────────

// The one that matters most. Each of these, accepted, is a restore writing outside the
// uploads folder — and wp-config.php holds the database credentials.
$traversal = [
    '../wp-config.php',
    '../../wp-config.php',
    '2026/../../wp-config.php',
    '2026/03/../../../etc/passwd',
    '..',
    '../',
    // Backslashes must be normalised before the check, not after. The other order sees this
    // as one harmless segment and then turns it into the traversal it was meant to catch.
    '..\\..\\wp-config.php',
    '2026\\..\\..\\x.jpg',
    'C:/windows/x.jpg',
    'c:\\windows\\x.jpg',
];

foreach ($traversal as $raw) {
    gf_assert_same(false, $m->t_safe($raw), "refuses traversal: {$raw}");
}

// A ".." is refused rather than resolved. Resolving "2026/04/../03/x.jpg" to "2026/03/x.jpg"
// would silently relocate the file, so a restore writes it somewhere the merchant never had
// it — a quiet wrong answer where a refusal is a loud right one.
gf_assert_same(false, $m->t_safe('2026/04/../03/photo.jpg'), 'traversal is refused, not resolved');

// ─── Control characters ──────────────────────────────────────────────────────

// A NUL truncates the string inside PHP's filesystem functions, so "safe.jpg\0.php" passes
// an extension check and is written to disk as executable PHP.
$control = [
    "2026/03/safe.jpg\0.php",
    "2026/03/\0.jpg",
    "2026/03/new\nline.jpg",
    "2026/03/return\r.jpg",
    "2026/03/tab\there.jpg",
    "2026/03/del\x7f.jpg",
];

foreach ($control as $raw) {
    gf_assert_same(false, $m->t_safe($raw), 'refuses a control character in the path');
}

// ─── Executable files ────────────────────────────────────────────────────────

// wp-content/uploads is web-served. A .php written there executes on most of the shared
// hosting this plugin runs on, so a restore that copies faithfully is a way to reinstate a
// shell somebody already cleaned up.
$executable = [
    '2026/03/shell.php',
    '2026/03/shell.PHP',
    '2026/03/shell.php5',
    '2026/03/shell.phtml',
    '2026/03/shell.phar',
    '2026/03/x.cgi',
    '2026/03/x.pl',
    '2026/03/x.py',
    '2026/03/x.sh',
    '2026/03/x.exe',
    '2026/03/x.aspx',
    '.htaccess',
    '2026/.htaccess',
    '2026/03/.htpasswd',
    // The double extension. Apache with AddHandler — a default on several Bangladeshi hosts —
    // matches on any extension in the name, so this is served as PHP despite ending in .jpg.
    '2026/03/shell.php.jpg',
    '2026/03/shell.phtml.png',
    '2026/03/a.b.php.c.jpg',
    // A trailing dot or space is stripped by some filesystems and kept by others, so the two
    // ends disagree about what the filename is.
    '2026/03/shell.php.',
    '2026/03/trailing.jpg ',
];

foreach ($executable as $raw) {
    gf_assert_same(false, $m->t_safe($raw), "refuses executable or ambiguous: {$raw}");
}

// And it must not overreach. Refusing these costs the merchant real images.
$fine = [
    '2026/03/php-tutorial-screenshot.png',
    '2026/03/notphp.jpg',
    '2026/03/phpstorm-logo.png',
    '2026/03/a.phpx.jpg',
    '2026/03/backup-plan-diagram.png',
];

foreach ($fine as $raw) {
    gf_assert($m->t_allowed($raw), "does not overreach on: {$raw}");
}

// ─── Length ──────────────────────────────────────────────────────────────────

gf_assert_same(false, $m->t_safe('2026/03/' . str_repeat('a', 500) . '.jpg'), 'refuses an over-long path');
gf_assert_same(false, $m->t_safe(''), 'refuses an empty path');
gf_assert_same(false, $m->t_safe('///'), 'refuses a path that is only separators');
gf_assert_same(false, $m->t_safe('/'), 'refuses a bare slash');

// ─── Absolute path resolution ────────────────────────────────────────────────

// The base is where every write must land. absolute_path() is the last check before a
// filesystem call, so anything that escapes here escapes for real.
$base = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/gf-media-test/';
@mkdir($base . '2026/03', 0777, true);

$inside = $m->t_absolute($base, '2026/03/photo.jpg');
gf_assert(
    is_string($inside) && strpos($inside, $base) === 0,
    'a legitimate path resolves inside the uploads folder'
);

foreach (['../escape.jpg', '2026/../../escape.jpg', 'shell.php'] as $raw) {
    gf_assert_same(false, $m->t_absolute($base, $raw), "absolute_path refuses: {$raw}");
}

// ─── Exclusions ──────────────────────────────────────────────────────────────

// The list the engine sends. Several popular backup plugins write their archives into
// wp-content/uploads, so without this a shop's media backup contains a copy of its own
// database — every night, counted against the storage ceiling.
$excluded_dirs = [
    'cache', 'backup', 'backups', 'ai1wm-backups', 'updraft', 'wpvivid',
    'guardify-tmp', 'node_modules', 'tmp', 'elementor/css', 'wc-logs',
];

$should_skip = [
    'cache/thumb.jpg',
    'backup/2026-01-01.zip',
    'ai1wm-backups/site.wpress',
    'updraft/backup.zip',
    'wpvivid/db.sql.gz',
    '2026/03/cache/resized.jpg',
    'elementor/css/post-12.css',
    'guardify-tmp/working.sql',
    'node_modules/pkg/index.js',
    'tmp/upload.part',
    'wc-logs/fatal.log',
];

foreach ($should_skip as $rel) {
    gf_assert($m->t_excluded($rel, $excluded_dirs), "skips: {$rel}");
}

// Matched on whole segments. A substring test would skip a product photograph called
// "cache-cleaner.jpg", and nothing would tell the merchant it was left out.
$should_keep = [
    '2026/03/cache-cleaner.jpg',
    '2026/03/backup-plan-poster.png',
    'backup-plans-2026/poster.png',
    '2026/03/tmp-fix.jpg',
    '2026/03/temporary.jpg',
    // A *file* named tmp.jpg or cache.png is media, not a folder to skip.
    '2026/03/tmp.jpg',
    '2026/03/cache.png',
    // A two-part entry excludes elementor/css without excluding elementor/fonts beside it.
    'elementor/fonts/x.woff2',
];

foreach ($should_keep as $rel) {
    gf_assert(!$m->t_excluded($rel, $excluded_dirs), "keeps: {$rel}");
}

// A directory is checked before it is descended into, so a cache folder holding fifty
// thousand generated thumbnails is never even opened.
gf_assert($m->t_dir_excluded('cache', $excluded_dirs), 'excludes the cache directory itself');
gf_assert($m->t_dir_excluded('2026/03/cache', $excluded_dirs), 'excludes a nested cache directory');
gf_assert($m->t_dir_excluded('elementor/css', $excluded_dirs), 'excludes a two-part directory entry');
gf_assert(!$m->t_dir_excluded('2026', $excluded_dirs), 'does not exclude an ordinary year folder');
gf_assert(!$m->t_dir_excluded('elementor', $excluded_dirs), 'does not exclude elementor wholesale');

// An empty list from an older engine must not exclude everything, and must not exclude
// nothing-by-accident either — it means "no exclusions".
gf_assert(!$m->t_excluded('cache/thumb.jpg', []), 'an empty exclusion list excludes nothing');

// ─── Content type ────────────────────────────────────────────────────────────

// The type has to match what the engine signed the presigned PUT with, or R2 refuses the
// upload with a signature mismatch and the file is silently never stored. These pairs are the
// contract between the two files.
$types = [
    '2026/03/photo.jpg'  => 'image/jpeg',
    '2026/03/photo.JPEG' => 'image/jpeg',
    '2026/03/photo.png'  => 'image/png',
    '2026/03/anim.gif'   => 'image/gif',
    '2026/03/photo.webp' => 'image/webp',
    '2026/03/photo.avif' => 'image/avif',
    '2026/03/doc.pdf'    => 'application/pdf',
    '2026/03/clip.mp4'   => 'video/mp4',
    '2026/03/clip.webm'  => 'video/webm',
    '2026/03/song.mp3'   => 'audio/mpeg',
    '2026/03/pack.zip'   => 'application/zip',
    '2026/03/font.woff2' => 'font/woff2',
    '2026/03/style.css'  => 'text/css',
    // Unknown and extensionless both fall to octet-stream, which is what the engine does.
    '2026/03/unknown.xyz'  => 'application/octet-stream',
    '2026/03/no-extension' => 'application/octet-stream',
    // SVG is XML that can carry script. Neither end types it as an image, so a stored object
    // can never be handed back as something a browser will execute.
    '2026/03/logo.svg' => 'application/octet-stream',
];

foreach ($types as $path => $want) {
    gf_assert_same($want, $m->t_content_type($path), "content type for {$path}");
}

// ─── Cost settings ───────────────────────────────────────────────────────────

// This whole class exists to keep a media backup off the merchant's critical path. These are
// the constants that decide whether it does, so a change to any of them should have to
// change a test.

gf_assert(
    Guardify_Media::SLICE_BUDGET <= 20,
    'a slice yields the PHP worker well inside a 30s max_execution_time'
);
gf_assert(
    Guardify_Media::PAGE_SIZE <= 500,
    'a manifest page stays within what the engine accepts'
);
gf_assert(
    Guardify_Media::SCAN_CHUNK <= 1000,
    'one slice reads a bounded number of directory entries'
);
gf_assert(
    Guardify_Media::JOB_MAX_AGE > Guardify_Media::SLICE_BUDGET * 10,
    'a job is not declared dead while it is still making progress'
);

// The feature is off until the merchant chooses it. Uploading a shop's entire media library
// is the largest thing this plugin can be asked to do, and no preset should decide that.
$defaults_probe = new ReflectionMethod('Guardify_Media', 'is_enabled');
gf_assert($defaults_probe->isPublic(), 'the enabled state is queryable');

$source = file_get_contents(__DIR__ . '/../includes/class-guardify-media.php');
gf_assert(
    strpos($source, "get_option('guardify_media_enabled', 'no')") !== false,
    'media sync defaults to off'
);

// Restore must never delete. A merchant running it on a live site to recover a few missing
// images must not lose the ones that are fine, and the code should not contain the means to.
gf_assert(
    strpos($source, 'unlink(') === false,
    'the media class never unlinks a merchant file directly'
);
gf_assert(
    substr_count($source, 'wp_delete_file(') === 2,
    'the only deletions are of the restore\'s own temp file, on the two failure paths'
);

// The stored mtime is put back after a download. Without it every restored file is dated
// "now", and since change detection is (path, size, mtime) the next sync would decide the
// whole library had changed and upload all of it again.
gf_assert(
    strpos($source, 'touch($target, $mtime)') !== false,
    'a restored file keeps its original modification time'
);

// A symlink is never followed. One pointing at the WordPress root or at /home would take the
// walk out of the uploads tree entirely, and one pointing at an ancestor would loop forever.
gf_assert(
    strpos($source, 'is_link($full)') !== false,
    'the walk refuses to follow symlinks'
);

// Media operations need manage_options, not the manage_woocommerce the rest of the plugin
// uses. Clearing a fraud flag is shop work; writing thousands of files into a web-served
// directory is not.
gf_assert(
    strpos($source, "current_user_can('manage_options')") !== false
        && strpos($source, "current_user_can('manage_woocommerce')") === false,
    'media operations require manage_options'
);

@rmdir($base . '2026/03');
@rmdir($base . '2026');
@rmdir($base);

gf_test_summary('media');
