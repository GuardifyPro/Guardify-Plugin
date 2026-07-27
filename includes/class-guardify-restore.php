<?php
defined('ABSPATH') || exit;

/**
 * Guardify Restore — bring a database back without being able to destroy it.
 *
 * The previous implementation ran the whole thing inside one admin-ajax request: a
 * blocking pre-restore backup, then a download, then `DROP TABLE IF EXISTS` followed by a
 * line-by-line import. On the shared hosting this plugin lives on that request dies partway
 * through, and it dies *after* the drops — leaving a shop with half its tables missing and
 * no way back. It then reported success whenever a single statement had succeeded, so the
 * merchant was told the restore worked.
 *
 * A restore is the one operation here where being wrong costs the whole business rather
 * than one order, so this is built the other way round: nothing the merchant already has
 * is touched until the new data is completely in place.
 *
 *   1. The engine verifies the archive's checksum against R2 before it authorises anything.
 *      A corrupt archive fails on Guardify's hardware with the shop untouched.
 *   2. The archive is downloaded in ranged slices and hashed again locally, because a
 *      truncated download and a corrupt archive look identical to an importer.
 *   3. Every statement is rewritten to a staging prefix and imported into *new* tables.
 *      The live site keeps serving throughout. A failure at any point here costs nothing
 *      but the staging tables, which are dropped.
 *   4. Only when the whole import has succeeded do the tables change, in a single
 *      `RENAME TABLE` — one atomic statement, so there is no moment where a customer can
 *      load a page against half-swapped data.
 *
 * The work runs in bounded slices for the same reason the backup does: a PHP worker held
 * for minutes on shared hosting is a slow shop for everyone browsing it.
 */
class Guardify_Restore {

    private static $instance = null;

    const JOB_OPTION  = 'guardify_restore_job';
    const SLICE_LOCK  = 'guardify_restore_slice_lock';
    const LAST_RESULT = 'guardify_restore_last_result';

    /** A job that has not finished in this long is abandoned and cleaned up. */
    const JOB_MAX_AGE = 3 * HOUR_IN_SECONDS;

    /** Seconds of wall clock one slice may use. */
    const SLICE_BUDGET = 15;

    /**
     * Decompression gets a longer budget than the rest.
     *
     * It is pure CPU with no database work, it usually finishes in well under a second,
     * and — unlike the import — it cannot be resumed cheaply, because a gzip stream has to
     * be read from the start to reach any offset. Giving it room to finish in one pass is
     * what keeps that from mattering.
     */
    const DECOMPRESS_BUDGET = 45;

    /** Bytes fetched per download slice. */
    const DOWNLOAD_CHUNK = 8388608; // 8 MB

    /** Prefix for the tables being built. Swapped in only once the import is complete. */
    const STAGING_INFIX = 'gfr_';

    /** Prefix the outgoing tables are parked under during the swap. */
    const RETIRED_INFIX = 'gfold_';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('guardify_restore_worker', [$this, 'run_slice']);

        add_action('wp_ajax_guardify_restore_start', [$this, 'ajax_start']);
        add_action('wp_ajax_guardify_restore_status', [$this, 'ajax_status']);
        add_action('wp_ajax_guardify_restore_abort', [$this, 'ajax_abort']);
    }

    // ─── Job lifecycle ───────────────────────────────────────────────────

    public function job_active() {
        $job = get_option(self::JOB_OPTION, null);
        if (!is_array($job) || empty($job['stage'])) {
            return false;
        }
        if (time() - (int) $job['started'] > self::JOB_MAX_AGE) {
            $this->fail($job, 'রিস্টোর সময়সীমা পার হয়েছে।');
            return false;
        }
        return true;
    }

    /**
     * Begin a restore from an authorisation issued by the portal.
     *
     * @param array $auth {token, checksum, file_size, restore_id}
     * @return true|WP_Error
     */
    public function start(array $auth) {
        if ($this->job_active()) {
            return new WP_Error('already_running', __('আরেকটি রিস্টোর চলছে।', 'guardify-pro'));
        }
        if (empty($auth['token'])) {
            return new WP_Error('no_token', __('রিস্টোর অনুমোদন পাওয়া যায়নি।', 'guardify-pro'));
        }

        $archive = wp_tempnam('guardify_restore_');
        if (!$archive) {
            return new WP_Error('temp_file', __('টেম্প ফাইল তৈরি করা যায়নি।', 'guardify-pro'));
        }
        // wp_tempnam creates the file; the download appends to it, so it starts empty.
        file_put_contents($archive, '');

        update_option(self::JOB_OPTION, [
            'stage'      => 'download',
            'token'      => (string) $auth['token'],
            'checksum'   => isset($auth['checksum']) ? (string) $auth['checksum'] : '',
            'expected'   => isset($auth['file_size']) ? (int) $auth['file_size'] : 0,
            'restore_id' => isset($auth['restore_id']) ? (string) $auth['restore_id'] : '',
            'archive'    => $archive,
            'sql'        => '',
            'downloaded' => 0,
            'gz_offset'  => 0,
            'sql_offset' => 0,
            'statements' => 0,
            'tables'     => [],
            'started'    => time(),
        ], false);

        delete_option(self::LAST_RESULT);
        $this->schedule_worker(0);

        return true;
    }

    private function schedule_worker($delay = 0) {
        if (!wp_next_scheduled('guardify_restore_worker')) {
            wp_schedule_single_event(time() + $delay, 'guardify_restore_worker');
        }
        if ($delay === 0 && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Advance the restore by one bounded slice.
     *
     * @return string|WP_Error 'idle' | 'working' | 'done'
     */
    public function run_slice() {
        if (!$this->job_active()) {
            return 'idle';
        }

        // Two cron ticks writing the same staging tables would interleave statements and
        // produce a database that imports without error and is wrong.
        if (get_transient(self::SLICE_LOCK)) {
            $this->schedule_worker(30);
            return 'working';
        }
        set_transient(self::SLICE_LOCK, 1, 10 * MINUTE_IN_SECONDS);

        $job = get_option(self::JOB_OPTION);

        switch ($job['stage']) {
            case 'download':
                $result = $this->step_download($job);
                break;
            case 'verify':
                $result = $this->step_verify($job);
                break;
            case 'decompress':
                $result = $this->step_decompress($job);
                break;
            case 'import':
                $result = $this->step_import($job);
                break;
            case 'swap':
                $result = $this->step_swap($job);
                break;
            default:
                $result = new WP_Error('bad_stage', __('অজানা রিস্টোর ধাপ।', 'guardify-pro'));
        }

        delete_transient(self::SLICE_LOCK);

        if (is_wp_error($result)) {
            return $this->fail($job, $result->get_error_message());
        }
        if ($result === 'done') {
            return $this->succeed($job);
        }

        update_option(self::JOB_OPTION, $job, false);
        $this->schedule_worker(20);
        return 'working';
    }

    // ─── Stages ──────────────────────────────────────────────────────────

    /**
     * Fetch the next range of the archive.
     *
     * Ranged rather than one long stream: a 300MB download on a Bangladeshi connection can
     * take minutes, and holding a PHP worker open that long is the thing this class exists
     * to avoid. Range requests also mean a dropped connection costs one chunk rather than
     * the whole file.
     */
    private function step_download(array &$job) {
        $api  = new Guardify_API();
        $path = '/api/v1/restore/fetch';

        $from = (int) $job['downloaded'];
        $to   = $from + self::DOWNLOAD_CHUNK - 1;

        $chunk_file = $job['archive'] . '.part';

        $response = wp_remote_get($api->engine_url() . $path, [
            'timeout'     => 120,
            'stream'      => true,
            'filename'    => $chunk_file,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => array_merge(
                $api->signed_headers('GET', $path),
                [
                    'X-GF-Restore-Token' => $job['token'],
                    'Range'              => 'bytes=' . $from . '-' . $to,
                    'Accept'             => 'application/gzip',
                ]
            ),
        ]);

        if (is_wp_error($response)) {
            @unlink($chunk_file);
            return new WP_Error('download_failed', __('ডাউনলোড ব্যর্থ: ', 'guardify-pro') . $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);

        // 206 is a served range; 200 means the server ignored Range and sent the whole
        // file, which is still usable as long as nothing was appended before it.
        if ($code === 200 && $from > 0) {
            @unlink($chunk_file);
            return new WP_Error('no_range', __('সার্ভার আংশিক ডাউনলোড সাপোর্ট করছে না।', 'guardify-pro'));
        }
        if ($code !== 200 && $code !== 206) {
            @unlink($chunk_file);
            return new WP_Error('download_failed', __('ডাউনলোড ব্যর্থ (HTTP ', 'guardify-pro') . $code . ')');
        }

        $written = $this->append_file($job['archive'], $chunk_file);
        @unlink($chunk_file);

        if ($written === false) {
            return new WP_Error('append_failed', __('ডাউনলোড করা অংশ যুক্ত করা যায়নি।', 'guardify-pro'));
        }

        $job['downloaded'] += $written;

        // A short chunk, or reaching the size the engine promised, means the archive is
        // complete. Both are checked: a server that ignores Range would otherwise loop.
        $complete = $written < self::DOWNLOAD_CHUNK
            || ($job['expected'] > 0 && $job['downloaded'] >= $job['expected']);

        if ($complete) {
            $job['stage'] = 'verify';
        }
        return 'working';
    }

    /**
     * Confirm the bytes on disk are the archive the engine authorised.
     *
     * The engine already matched this checksum against R2, so a mismatch here means the
     * download was truncated or altered in transit. Checking again locally costs one read
     * of a file that is about to be read anyway, and the alternative is discovering the
     * problem partway through an import.
     */
    private function step_verify(array &$job) {
        $size = @filesize($job['archive']);
        if ($size === false || $size <= 0) {
            return new WP_Error('empty_archive', __('ডাউনলোড করা ফাইল খালি।', 'guardify-pro'));
        }
        if ($job['expected'] > 0 && $size !== (int) $job['expected']) {
            return new WP_Error(
                'size_mismatch',
                sprintf(__('ফাইলের আকার মেলেনি (%d বনাম %d বাইট)।', 'guardify-pro'), $size, (int) $job['expected'])
            );
        }

        if ($job['checksum'] !== '') {
            $actual = hash_file('sha256', $job['archive']);
            if (!hash_equals($job['checksum'], (string) $actual)) {
                return new WP_Error('checksum_mismatch', __('ফাইলের চেকসাম মেলেনি — ডাউনলোড সম্পূর্ণ হয়নি।', 'guardify-pro'));
            }
        }

        $sql = $job['archive'] . '.sql';
        @unlink($sql);
        $job['sql']   = $sql;
        $job['stage'] = 'decompress';

        return 'working';
    }

    /**
     * Expand the archive to plain SQL on disk.
     *
     * The import needs to resume across cron runs, and resuming inside a gzip stream means
     * decompressing everything before the offset every time — quadratic on a large dump.
     * Expanding once to a plain file makes resumption an `fseek`, which is free.
     *
     * Resumption here works by re-reading and discarding, which is the expensive path. It
     * is bounded and always terminates, and with the budget above it is rarely reached at
     * all: a typical shop's archive expands in under a second.
     */
    private function step_decompress(array &$job) {
        $gz = gzopen($job['archive'], 'rb');
        if (!$gz) {
            return new WP_Error('gz_open', __('আর্কাইভ খোলা যায়নি।', 'guardify-pro'));
        }

        if ((int) $job['gz_offset'] > 0 && gzseek($gz, (int) $job['gz_offset']) !== 0) {
            gzclose($gz);
            return new WP_Error('gz_seek', __('আর্কাইভের অবস্থানে ফেরা যায়নি।', 'guardify-pro'));
        }

        $out = fopen($job['sql'], (int) $job['gz_offset'] > 0 ? 'ab' : 'wb');
        if (!$out) {
            gzclose($gz);
            return new WP_Error('sql_open', __('অস্থায়ী ফাইল লেখা যায়নি।', 'guardify-pro'));
        }

        $deadline = microtime(true) + self::DECOMPRESS_BUDGET;
        $done     = false;

        while (microtime(true) < $deadline) {
            $buf = gzread($gz, 1048576);
            if ($buf === false) {
                fclose($out);
                gzclose($gz);
                return new WP_Error('gz_read', __('আর্কাইভ পড়া যায়নি — ফাইলটি ক্ষতিগ্রস্ত হতে পারে।', 'guardify-pro'));
            }
            if ($buf === '') {
                $done = true;
                break;
            }
            fwrite($out, $buf);
            $job['gz_offset'] += strlen($buf);
        }

        fclose($out);
        gzclose($gz);

        if ($done) {
            $job['stage'] = 'import';
            // Whatever a previous attempt left behind must go, or its rows would be merged
            // into this restore and the merchant would get a database that is neither.
            $this->drop_staging();
        }
        return 'working';
    }

    /**
     * Apply statements into staging tables until the budget runs out.
     */
    private function step_import(array &$job) {
        global $wpdb;

        $fh = fopen($job['sql'], 'rb');
        if (!$fh) {
            return new WP_Error('sql_open', __('SQL ফাইল খোলা যায়নি।', 'guardify-pro'));
        }
        if ((int) $job['sql_offset'] > 0) {
            fseek($fh, (int) $job['sql_offset']);
        }

        $deadline = microtime(true) + self::SLICE_BUDGET;
        $buffer   = '';
        $done     = false;

        // Foreign key checks are off for the same reason the dump turns them off: tables
        // arrive in whatever order they were written, and a reference to a table that has
        // not been created yet is not an error here.
        $wpdb->query('SET foreign_key_checks = 0');

        while (microtime(true) < $deadline) {
            $line = fgets($fh, 1048576);
            if ($line === false) {
                $done = true;
                break;
            }

            // Comments and blanks are only skipped between statements. Inside one, a line
            // starting with "--" is row data, and dropping it corrupts the INSERT.
            if ($buffer === '') {
                $trimmed = ltrim($line);
                if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                    $job['sql_offset'] = ftell($fh);
                    continue;
                }
            }

            $buffer .= $line;

            if (substr(rtrim($buffer), -1) !== ';') {
                continue;
            }

            $sql    = trim($buffer);
            $buffer = '';
            $job['sql_offset'] = ftell($fh);

            if ($sql === '') {
                continue;
            }

            $rewritten = self::to_staging($sql, $wpdb->prefix, $table);
            if ($rewritten === null) {
                // A statement that names no table of ours — the header's SET statements —
                // is applied as-is. Anything else unrecognised is skipped rather than run
                // against a database it was not addressed to.
                if (stripos($sql, 'SET ') === 0) {
                    $wpdb->query($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                }
                continue;
            }

            if ($table !== '' && !in_array($table, $job['tables'], true)) {
                $job['tables'][] = $table;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- importing a verified dump
            if ($wpdb->query($rewritten) === false) {
                fclose($fh);
                // Nothing live has been touched, so failing here costs only the staging
                // tables. That is the whole point of importing into them first.
                return new WP_Error(
                    'import_failed',
                    __('ইম্পোর্ট ব্যর্থ: ', 'guardify-pro') . $wpdb->last_error . __(' (কোনো লাইভ টেবিল পরিবর্তন হয়নি)', 'guardify-pro')
                );
            }
            $job['statements']++;
        }

        fclose($fh);

        if ($done) {
            if (empty($job['tables'])) {
                return new WP_Error('no_tables', __('আর্কাইভে কোনো টেবিল পাওয়া যায়নি।', 'guardify-pro'));
            }
            $job['stage'] = 'swap';
        }
        return 'working';
    }

    /**
     * Put the restored tables live.
     *
     * One `RENAME TABLE` naming every pair. MySQL performs a multi-table rename atomically
     * and under a single lock, so there is no window in which a customer's page load can
     * see half the old database and half the new one. Renaming table by table would create
     * exactly that window, and on a shop mid-checkout it would produce orders written
     * against a schema that no longer matches.
     */
    private function step_swap(array &$job) {
        global $wpdb;

        $pairs = [];
        $live  = [];

        foreach ($job['tables'] as $table) {
            $staging = $this->staging_name($table);
            $retired = $this->retired_name($table);

            // Only tables that exist live get parked; a table the archive introduces has
            // nothing to rename out of the way.
            if ($this->table_exists($table)) {
                $wpdb->query("DROP TABLE IF EXISTS `{$retired}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                $pairs[] = "`{$table}` TO `{$retired}`";
                $live[]  = $retired;
            }
            $pairs[] = "`{$staging}` TO `{$table}`";
        }

        if (empty($pairs)) {
            return new WP_Error('nothing_to_swap', __('পরিবর্তন করার মতো কিছু পাওয়া যায়নি।', 'guardify-pro'));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers built from SHOW TABLES
        if ($wpdb->query('RENAME TABLE ' . implode(', ', $pairs)) === false) {
            return new WP_Error(
                'swap_failed',
                __('টেবিল পরিবর্তন ব্যর্থ: ', 'guardify-pro') . $wpdb->last_error . __(' (সাইট অপরিবর্তিত আছে)', 'guardify-pro')
            );
        }

        // The old tables are dropped after the swap, not before. Until this line the
        // previous database still exists under another name, which is the last chance to
        // recover from a restore of the wrong archive.
        foreach ($live as $retired) {
            $wpdb->query("DROP TABLE IF EXISTS `{$retired}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        // The restored options table carries the archive's own cached values. Clearing the
        // caches WordPress keeps in memory avoids serving the pre-restore state for the
        // rest of this request.
        wp_cache_flush();

        return 'done';
    }

    // ─── Statement rewriting ─────────────────────────────────────────────

    /**
     * Rewrite a statement to address the staging tables.
     *
     * Only the table name at the head of the statement is touched. Row data can contain
     * anything at all — a product description quoting SQL, a serialised option holding a
     * table name — and a blind string replacement across the whole statement would corrupt
     * it. Anchoring to the start is what makes this safe.
     *
     * @param string      $sql    Statement, trimmed.
     * @param string      $prefix The site's table prefix.
     * @param string|null $table  Set to the original table name on a match.
     * @return string|null Rewritten statement, or null if it names no table of ours.
     */
    public static function to_staging($sql, $prefix, &$table = null) {
        $table = '';

        $pattern = '/^(DROP TABLE IF EXISTS|DROP TABLE|CREATE TABLE IF NOT EXISTS|CREATE TABLE|INSERT INTO|REPLACE INTO|LOCK TABLES|ALTER TABLE)\s+`([^`]+)`/i';

        if (!preg_match($pattern, $sql, $m)) {
            return null;
        }

        $name = $m[2];

        // A statement naming a table outside this install's prefix is not ours to run. On
        // shared hosting the same database can hold another site, and an archive that
        // somehow names its tables must not be able to write to them.
        if (strpos($name, $prefix) !== 0) {
            return null;
        }

        $table   = $name;
        $staging = $prefix . self::STAGING_INFIX . substr($name, strlen($prefix));

        return preg_replace(
            $pattern,
            '$1 `' . str_replace('$', '\\$', $staging) . '`',
            $sql,
            1
        );
    }

    private function staging_name($table) {
        global $wpdb;
        return $wpdb->prefix . self::STAGING_INFIX . substr($table, strlen($wpdb->prefix));
    }

    private function retired_name($table) {
        global $wpdb;
        return $wpdb->prefix . self::RETIRED_INFIX . substr($table, strlen($wpdb->prefix));
    }

    private function table_exists($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
        return $found === $table;
    }

    /**
     * Remove staging and parked tables left by an abandoned attempt.
     */
    private function drop_staging() {
        global $wpdb;

        foreach ([self::STAGING_INFIX, self::RETIRED_INFIX] as $infix) {
            $like = $wpdb->esc_like($wpdb->prefix . $infix) . '%';
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below
            $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
            foreach ((array) $tables as $t) {
                $wpdb->query("DROP TABLE IF EXISTS `{$t}`"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }
    }

    // ─── Terminal states ─────────────────────────────────────────────────

    private function succeed(array $job) {
        $this->report($job, 'applied', '');
        $this->cleanup($job);

        update_option(self::LAST_RESULT, [
            'ok'         => true,
            'message'    => __('ডাটাবেইজ সফলভাবে রিস্টোর হয়েছে।', 'guardify-pro'),
            'statements' => (int) $job['statements'],
            'tables'     => count($job['tables']),
            'time'       => time(),
        ], false);

        return 'done';
    }

    private function fail(array $job, $message) {
        // Staging tables go; live tables were never touched.
        $this->drop_staging();
        $this->report($job, 'failed', $message);
        $this->cleanup($job);

        update_option(self::LAST_RESULT, [
            'ok'      => false,
            'message' => $message,
            'time'    => time(),
        ], false);

        error_log('Guardify Restore: ' . $message);
        return new WP_Error('restore_failed', $message);
    }

    /**
     * Tell the engine how it went, so the answer exists somewhere the merchant's support
     * request can reach — not only in a WordPress error log they cannot read.
     */
    private function report(array $job, $status, $error) {
        if (empty($job['token'])) {
            return;
        }
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }
        $api->post('/api/v1/restore/complete', [
            'status'     => $status,
            'statements' => (int) $job['statements'],
            'error'      => (string) $error,
        ], ['headers' => ['X-GF-Restore-Token' => $job['token']]]);
    }

    private function cleanup(array $job) {
        foreach (['archive', 'sql'] as $key) {
            if (!empty($job[$key]) && file_exists($job[$key])) {
                wp_delete_file($job[$key]);
            }
        }
        if (!empty($job['archive']) && file_exists($job['archive'] . '.part')) {
            wp_delete_file($job['archive'] . '.part');
        }
        delete_option(self::JOB_OPTION);
        delete_transient(self::SLICE_LOCK);
        wp_clear_scheduled_hook('guardify_restore_worker');
    }

    /**
     * Append one file to another without loading either into memory.
     *
     * @return int|false Bytes appended.
     */
    private function append_file($target, $source) {
        $in = fopen($source, 'rb');
        if (!$in) {
            return false;
        }
        $out = fopen($target, 'ab');
        if (!$out) {
            fclose($in);
            return false;
        }

        $written = 0;
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false) {
                break;
            }
            $written += (int) fwrite($out, $buf);
        }

        fclose($in);
        fclose($out);
        return $written;
    }

    // ─── AJAX ────────────────────────────────────────────────────────────

    private function guard() {
        check_ajax_referer('guardify_nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
    }

    /**
     * Start a restore from a token the merchant obtained in the Guardify dashboard.
     *
     * The authorisation is deliberately not issued here. Verifying an archive end to end
     * and confirming the target domain are decisions that belong on Guardify's side, where
     * they can be audited and where a 400MB integrity check does not run on the merchant's
     * hosting.
     */
    public function ajax_start() {
        $this->guard();

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
            wp_send_json_error(__('সঠিক রিস্টোর কোড দিন। কোডটি Guardify ড্যাশবোর্ড থেকে পাবেন।', 'guardify-pro'));
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error(__('প্লাগইন সংযুক্ত নয়।', 'guardify-pro'));
        }

        $started = $this->start([
            'token'     => $token,
            'checksum'  => isset($_POST['checksum']) ? sanitize_text_field(wp_unslash($_POST['checksum'])) : '',
            'file_size' => isset($_POST['file_size']) ? absint($_POST['file_size']) : 0,
        ]);

        if (is_wp_error($started)) {
            wp_send_json_error($started->get_error_message());
        }

        wp_send_json_success([
            'queued'  => true,
            'message' => __('রিস্টোর শুরু হয়েছে। আপনার সাইট চালু থাকবে — নতুন ডাটা প্রস্তুত হলেই কেবল বদল হবে।', 'guardify-pro'),
        ]);
    }

    public function ajax_status() {
        $this->guard();

        $job = get_option(self::JOB_OPTION, null);

        if (is_array($job) && !empty($job['stage'])) {
            $labels = [
                'download'   => __('ব্যাকআপ ডাউনলোড হচ্ছে…', 'guardify-pro'),
                'verify'     => __('ফাইল যাচাই হচ্ছে…', 'guardify-pro'),
                'decompress' => __('ফাইল খোলা হচ্ছে…', 'guardify-pro'),
                'import'     => __('নতুন টেবিল তৈরি হচ্ছে (সাইট চালু আছে)…', 'guardify-pro'),
                'swap'       => __('পরিবর্তন প্রয়োগ হচ্ছে…', 'guardify-pro'),
            ];
            wp_send_json_success([
                'running'    => true,
                'stage'      => $job['stage'],
                'message'    => isset($labels[$job['stage']]) ? $labels[$job['stage']] : __('চলছে…', 'guardify-pro'),
                'statements' => (int) $job['statements'],
                'downloaded' => (int) $job['downloaded'],
                'expected'   => (int) $job['expected'],
            ]);
        }

        $last = get_option(self::LAST_RESULT, null);
        wp_send_json_success(['running' => false, 'last' => is_array($last) ? $last : null]);
    }

    /**
     * Abandon a running restore.
     *
     * Safe at every stage before the swap, which is every stage a merchant can reach the
     * button in — the swap itself is one statement and cannot be interrupted from here.
     */
    public function ajax_abort() {
        $this->guard();

        if (!$this->abort(__('ব্যবহারকারী রিস্টোর বাতিল করেছেন।', 'guardify-pro'))) {
            wp_send_json_success(['message' => __('কোনো রিস্টোর চলছে না।', 'guardify-pro')]);
        }

        wp_send_json_success(['message' => __('রিস্টোর বাতিল হয়েছে। সাইটে কোনো পরিবর্তন হয়নি।', 'guardify-pro')]);
    }

    /**
     * Abandon a running restore from anywhere in the plugin.
     *
     * Public because the domain change drives a restore of its own and has to be able to
     * call it off. Going through here rather than deleting the job row directly is what
     * ensures the staging tables are dropped, the temp files removed and the engine told —
     * a caller that only forgets the job leaves a full copy of a database on the merchant's
     * disk and an authorisation open on ours.
     *
     * @return bool Whether there was anything to abort.
     */
    public function abort($reason = '') {
        $job = get_option(self::JOB_OPTION, null);
        if (!is_array($job)) {
            return false;
        }

        $this->fail($job, $reason !== '' ? $reason : __('রিস্টোর বাতিল করা হয়েছে।', 'guardify-pro'));
        return true;
    }
}
