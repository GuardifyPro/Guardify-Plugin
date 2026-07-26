<?php
defined('ABSPATH') || exit;

/**
 * Guardify Backup — managed WordPress database backup.
 *
 * Restore lives in Guardify_Restore. It was moved out because the two have opposite
 * risk profiles: a failed backup costs an archive, a failed restore costs the shop, and
 * the second needed a structure — staging tables and an atomic swap — that has nothing to
 * do with producing a dump.
 *
 * The dump runs as a *resumable job* rather than one long request. A backup is enqueued
 * (by the schedule, by the merchant, or by the Guardify portal), and a worker cron then
 * takes bounded slices of it — a few seconds of work at a time — appending to a single
 * gzip file until the whole database is written, and only then uploading.
 *
 * That structure exists because of where this code runs. Bangladeshi shops are almost
 * always on shared hosting with a 30-second max_execution_time, a low memory_limit and
 * CPU quotas that throttle the whole account when one process runs hot. A backup that
 * tries to dump a 400MB database in one pass on that hardware does not produce a slow
 * backup; it produces a timeout, a half-written file, and a shop that is measurably
 * slower for every customer browsing it at the time. Bounded slices trade wall-clock
 * time — which nobody is watching, since the archive lands on Guardify's side — for
 * never holding the merchant's PHP worker long enough to matter.
 *
 * The other half of the cost is how much is written at all:
 *   - rows are paged by primary key, not LIMIT/OFFSET, so page 800 costs the same as
 *     page 1 instead of making MySQL walk 400,000 rows to throw them away;
 *   - compression is level 6, not 9, which is a few percent larger for a fraction of
 *     the CPU — and Guardify pays for the storage, not the merchant;
 *   - rows with no restore value (transients, carts, completed job queues) are skipped
 *     while their tables' structure is kept, so a restore still comes up clean.
 */
class Guardify_Backup {

    private static $instance = null;

    /** Job state for the in-progress dump. */
    const JOB_OPTION = 'guardify_backup_job';

    /** A job that has not finished in this long is assumed dead and cleaned up. */
    const JOB_MAX_AGE = 2 * HOUR_IN_SECONDS;

    /** Held for the duration of one slice so two cron ticks cannot write the same file. */
    const SLICE_LOCK = 'guardify_backup_slice_lock';

    /** Result of the last completed job, for the admin screen. */
    const LAST_RESULT = 'guardify_backup_last_result';

    /** Seconds of wall clock one slice may use before yielding the PHP worker. */
    const SLICE_BUDGET = 15;

    /** Rows read per query. Small enough that one page never dominates memory_limit. */
    const PAGE_SIZE = 500;

    /** Bytes of INSERT text buffered before writing, to keep gzwrite calls few and large. */
    const WRITE_BUFFER = 512000;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // The schedule and the portal both only ever *enqueue*; the worker does the work.
        add_action('guardify_scheduled_backup', [$this, 'run_scheduled_backup']);
        add_action('guardify_check_pending_backup', [$this, 'process_pending_requests']);
        add_action('guardify_backup_worker', [$this, 'run_slice']);

        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // The pending poll is a single small signed GET, not a backup. It stays frequent
        // because it is what makes "Back up now" in the Guardify portal feel immediate;
        // the expensive part it triggers is bounded by the worker.
        if (!wp_next_scheduled('guardify_check_pending_backup')) {
            wp_schedule_event(time(), 'guardify_every_5min', 'guardify_check_pending_backup');
        }

        add_action('admin_init', [$this, 'maybe_check_pending']);

        add_action('wp_ajax_guardify_backup_now', [$this, 'ajax_backup_now']);
        add_action('wp_ajax_guardify_backup_status', [$this, 'ajax_backup_status']);
        add_action('wp_ajax_guardify_backup_list', [$this, 'ajax_backup_list']);
        add_action('wp_ajax_guardify_backup_save_schedule', [$this, 'ajax_save_schedule']);
    }

    /**
     * Register custom cron schedules.
     */
    public function add_cron_schedules($schedules) {
        $schedules['guardify_every_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'প্রতি ৫ মিনিটে',
        ];
        $schedules['guardify_every_6h'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => 'প্রতি ৬ ঘণ্টায়',
        ];
        $schedules['guardify_every_12h'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display'  => 'প্রতি ১২ ঘণ্টায়',
        ];
        return $schedules;
    }

    /**
     * Schedule or reschedule the backup cron.
     */
    public function schedule_backup() {
        wp_clear_scheduled_hook('guardify_scheduled_backup');

        $enabled = get_option('guardify_backup_enabled', 'no');
        if ($enabled !== 'yes') {
            return;
        }

        $frequency = get_option('guardify_backup_frequency', 'daily');
        $time_str  = get_option('guardify_backup_time', '05:00');
        $timezone  = get_option('guardify_backup_timezone', 'Asia/Dhaka');

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Exception $e) {
            $tz = new DateTimeZone('Asia/Dhaka');
        }

        $now  = new DateTime('now', $tz);
        $next = new DateTime($now->format('Y-m-d') . ' ' . $time_str, $tz);

        if ($next <= $now) {
            $next->modify('+1 day');
        }

        $recurrence_map = [
            'every_6h'  => 'guardify_every_6h',
            'every_12h' => 'guardify_every_12h',
            'daily'     => 'daily',
            'weekly'    => 'weekly',
        ];
        $recurrence = isset($recurrence_map[$frequency]) ? $recurrence_map[$frequency] : 'daily';

        wp_schedule_event($next->getTimestamp(), $recurrence, 'guardify_scheduled_backup');
    }

    /**
     * WP Cron callback — enqueue the nightly backup.
     */
    public function run_scheduled_backup() {
        $started = $this->start_backup('স্বয়ংক্রিয় ব্যাকআপ');
        if (is_wp_error($started)) {
            error_log('Guardify Backup: ' . $started->get_error_message());
        }
    }

    /**
     * Check for pending backup requests on admin page load (throttled to once per 5 min).
     */
    public function maybe_check_pending() {
        if (get_transient('guardify_pending_checked')) {
            return;
        }
        set_transient('guardify_pending_checked', true, 5 * MINUTE_IN_SECONDS);
        $this->process_pending_requests();
    }

    /**
     * Poll the engine for backup requests raised from the Guardify portal.
     *
     * Only the first request is taken. The rest stay pending and are picked up by a later
     * tick, because running a second dump before the first finished would double the load
     * this class exists to bound.
     */
    public function process_pending_requests() {
        if ($this->job_active()) {
            return;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        $result = $api->get('/api/v1/backup/pending');
        if (empty($result['pending']) || !is_array($result['pending'])) {
            return;
        }

        $request    = $result['pending'][0];
        $request_id = isset($request['id']) ? sanitize_text_field($request['id']) : '';
        $note       = isset($request['note']) ? sanitize_text_field($request['note']) : 'রিমোট ব্যাকআপ';

        if ($request_id === '') {
            return;
        }

        // The request is acknowledged when the job finishes, not now. Acknowledging up
        // front would tell the portal a backup happened while it was still queued, and
        // lose it entirely if this site never got round to running it.
        $started = $this->start_backup($note, $request_id);
        if (is_wp_error($started)) {
            $api->post('/api/v1/backup/ack', ['id' => $request_id]);
            error_log('Guardify Remote Backup: ' . $started->get_error_message());
        }
    }

    // ─── Job lifecycle ───────────────────────────────────────────────────

    /**
     * Whether a dump is currently in flight.
     */
    public function job_active() {
        $job = get_option(self::JOB_OPTION, null);
        if (!is_array($job) || empty($job['path'])) {
            return false;
        }
        // A job whose worker died — a fatal, a host restart mid-slice — would otherwise
        // block every future backup forever, which is a silent failure of the whole feature.
        if (time() - (int) $job['started'] > self::JOB_MAX_AGE) {
            $this->finish_job(new WP_Error('stalled', 'ব্যাকআপ সময়সীমা পার হয়েছে।'));
            return false;
        }
        return true;
    }

    /**
     * Enqueue a backup and kick the worker.
     *
     * @param string $note   Note stored with the archive.
     * @param string $ack_id Portal request id to acknowledge on completion.
     * @return true|WP_Error
     */
    public function start_backup($note = '', $ack_id = '', $spawn = true) {
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।');
        }
        if ($this->job_active()) {
            return new WP_Error('already_running', 'আরেকটি ব্যাকআপ চলছে।');
        }

        $tables = $this->site_tables();
        if (empty($tables)) {
            return new WP_Error('no_tables', 'কোনো ডাটাবেইজ টেবিল পাওয়া যায়নি।');
        }

        $path = wp_tempnam('guardify_backup_');
        if (!$path) {
            return new WP_Error('temp_file', 'টেম্প ফাইল তৈরি করা যায়নি।');
        }

        // Level 6, not 9. Level 9 spends several times the CPU of level 6 to save a low
        // single-digit percentage on SQL text, and that CPU comes out of the merchant's
        // hosting quota while the bytes it saves come out of Guardify's storage bill.
        $gz = gzopen($path, 'wb6');
        if (!$gz) {
            wp_delete_file($path);
            return new WP_Error('gz_open', 'কম্প্রেশন শুরু করা যায়নি।');
        }

        $header  = "-- Guardify Pro Database Backup\n";
        $header .= '-- Date: ' . gmdate('Y-m-d H:i:s') . " UTC\n";
        $header .= '-- Site: ' . site_url() . "\n";
        $header .= '-- WordPress: ' . get_bloginfo('version') . "\n";
        $header .= '-- Tables: ' . count($tables) . "\n";
        $header .= "SET NAMES utf8mb4;\n";
        $header .= "SET foreign_key_checks = 0;\n\n";
        gzwrite($gz, $header);
        gzclose($gz);

        update_option(self::JOB_OPTION, [
            'path'    => $path,
            'note'    => (string) $note,
            'ack'     => (string) $ack_id,
            'queue'   => array_values($tables),
            'table'   => '',
            'pk'      => [],
            'cursor'  => null,
            'offset'  => 0,
            'rows'    => 0,
            'raw'     => strlen($header),
            'total'   => count($tables),
            'started' => time(),
        ], false);

        delete_option(self::LAST_RESULT);
        if ($spawn) {
            $this->schedule_worker();
        }

        return true;
    }

    /**
     * Run the worker again shortly. A single-shot event rather than a recurring one, so a
     * finished job leaves nothing behind on the schedule.
     */
    private function schedule_worker($delay = 0) {
        if (!wp_next_scheduled('guardify_backup_worker')) {
            wp_schedule_single_event(time() + $delay, 'guardify_backup_worker');
        }
        if ($delay === 0 && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Take one bounded slice of the in-flight dump.
     *
     * Returns 'idle' when there is nothing to do, 'working' when more slices are needed,
     * 'done' when the archive was uploaded, or a WP_Error.
     */
    public function run_slice() {
        global $wpdb;

        if (!$this->job_active()) {
            return 'idle';
        }

        // Two cron ticks can overlap on a busy site. Both appending to the same gzip file
        // would interleave INSERT statements mid-line and produce an archive that fails to
        // import — the worst possible failure, because it looks like a backup until the day
        // someone needs it.
        if (get_transient(self::SLICE_LOCK)) {
            $this->schedule_worker(60);
            return 'working';
        }
        set_transient(self::SLICE_LOCK, 1, 5 * MINUTE_IN_SECONDS);

        $job = get_option(self::JOB_OPTION);

        if (!file_exists($job['path'])) {
            // Some hosts sweep the temp directory. Better to fail loudly than to upload a
            // truncated archive.
            delete_transient(self::SLICE_LOCK);
            return $this->finish_job(new WP_Error('temp_lost', 'অসম্পূর্ণ ব্যাকআপ ফাইল হারিয়ে গেছে।'));
        }

        // Append mode. Each slice adds a gzip member; concatenated members are a valid
        // gzip stream and gzgets() reads across them transparently, which is how the
        // importer already works.
        $gz = gzopen($job['path'], 'ab6');
        if (!$gz) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_job(new WP_Error('gz_open', 'কম্প্রেশন চালিয়ে যাওয়া যায়নি।'));
        }

        $budget   = (int) apply_filters('guardify_backup_slice_budget', self::SLICE_BUDGET);
        $deadline = microtime(true) + max(3, $budget);
        $complete = false;

        while (microtime(true) < $deadline) {
            if ($job['table'] === '') {
                if (empty($job['queue'])) {
                    gzwrite($gz, "SET foreign_key_checks = 1;\n");
                    $complete = true;
                    break;
                }

                $job['table']  = array_shift($job['queue']);
                $job['pk']     = $this->primary_key_columns($job['table']);
                $job['cursor'] = null;
                $job['offset'] = 0;

                $create = $wpdb->get_row("SHOW CREATE TABLE `{$job['table']}`", ARRAY_N);
                if ($create) {
                    gzwrite($gz, "DROP TABLE IF EXISTS `{$job['table']}`;\n");
                    gzwrite($gz, $create[1] . ";\n\n");
                }

                // Structure is written, rows are not. A restore brings the table back empty
                // and WooCommerce or the plugin that owns it repopulates it on demand.
                if ($this->skips_data($job['table'])) {
                    gzwrite($gz, "-- (data skipped: regenerable)\n\n");
                    $job['table'] = '';
                    continue;
                }
                continue;
            }

            $written = $this->write_page($gz, $job);
            if ($written === 0) {
                gzwrite($gz, "\n");
                $job['table'] = '';
            }
        }

        gzclose($gz);

        if (!$complete) {
            update_option(self::JOB_OPTION, $job, false);
            delete_transient(self::SLICE_LOCK);
            $this->schedule_worker(30);
            return 'working';
        }

        // The dump is finished; upload it. This part is network-bound rather than CPU-bound,
        // so it does not need slicing — and it streams from disk, never into memory.
        update_option(self::JOB_OPTION, $job, false);
        $result = $this->upload_to_engine($job['path'], $job['note'], (int) $job['raw']);
        delete_transient(self::SLICE_LOCK);

        return $this->finish_job($result);
    }

    /**
     * Write one page of a table's rows. Returns how many rows were written.
     */
    protected function write_page($gz, array &$job) {
        global $wpdb;

        $table  = $job['table'];
        $pk     = $job['pk'];
        $filter = $this->row_filter($table);

        // Keyset pagination on a single-column primary key: "the next 500 rows after the
        // last id I wrote". The index does the seeking, so every page costs the same.
        // LIMIT/OFFSET makes MySQL read and discard every row before the offset, which
        // turns a large table into quadratic work and is the single biggest reason a
        // dump on shared hosting drags the whole site down.
        if (count($pk) === 1) {
            $col   = $pk[0];
            $where = $filter === '' ? '' : " AND {$filter}";
            if ($job['cursor'] === null) {
                $where = $filter === '' ? '' : " WHERE {$filter}";
                $sql = $wpdb->prepare(
                    "SELECT * FROM `{$table}`{$where} ORDER BY `{$col}` ASC LIMIT %d",
                    self::PAGE_SIZE
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$col}` > %s{$where} ORDER BY `{$col}` ASC LIMIT %d",
                    $job['cursor'],
                    self::PAGE_SIZE
                );
            }
        } else {
            // Composite or absent primary key. Ordering by every key column keeps the paging
            // deterministic — without it, OFFSET can repeat or skip rows between pages — but
            // the offset scan is unavoidable here. In a WordPress schema these tables
            // (term_relationships and friends) are small.
            $order = empty($pk)
                ? ''
                : ' ORDER BY ' . implode(', ', array_map(function ($c) { return "`{$c}` ASC"; }, $pk));
            $where = $filter === '' ? '' : " WHERE {$filter}";
            $sql = $wpdb->prepare(
                "SELECT * FROM `{$table}`{$where}{$order} LIMIT %d OFFSET %d",
                self::PAGE_SIZE,
                $job['offset']
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built with prepare() above
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return 0;
        }

        $columns = '(' . implode(',', array_map(function ($c) {
            return '`' . $c . '`';
        }, array_keys($rows[0]))) . ')';

        // Extended INSERTs: one statement per batch instead of one per row. Fewer, larger
        // statements compress better and import an order of magnitude faster, which matters
        // on the day the merchant is actually restoring.
        $buffer = '';
        $tuples = [];
        foreach ($rows as $row) {
            $tuples[] = '(' . implode(',', array_map([$this, 'quote'], $row)) . ')';

            if (strlen(implode('', $tuples)) >= self::WRITE_BUFFER) {
                $buffer .= "INSERT INTO `{$table}` {$columns} VALUES " . implode(',', $tuples) . ";\n";
                $tuples = [];
                gzwrite($gz, $buffer);
                $job['raw'] += strlen($buffer);
                $buffer = '';
            }
        }
        if (!empty($tuples)) {
            $buffer .= "INSERT INTO `{$table}` {$columns} VALUES " . implode(',', $tuples) . ";\n";
        }
        if ($buffer !== '') {
            gzwrite($gz, $buffer);
            $job['raw'] += strlen($buffer);
        }

        $count = count($rows);
        $job['rows'] += $count;

        if (count($pk) === 1) {
            $last = end($rows);
            $job['cursor'] = $last[$pk[0]];
        } else {
            $job['offset'] += $count;
        }

        // A short page means the table is exhausted; say so without paying for the extra
        // query that would otherwise be needed to discover it.
        return $count < self::PAGE_SIZE ? 0 : $count;
    }

    /**
     * Render one column value as a SQL literal.
     */
    protected function quote($value) {
        if (is_null($value)) {
            return 'NULL';
        }
        // Binary columns — a serialised object with a raw byte in it, an image in a meta
        // field — are not valid UTF-8, and escaping them as text corrupts them silently.
        // A hex literal round-trips byte for byte.
        if (!preg_match('//u', $value)) {
            return '0x' . bin2hex($value);
        }
        return "'" . esc_sql($value) . "'";
    }

    /**
     * Tables belonging to this install.
     *
     * The prefix is escaped for LIKE. Unescaped, the underscore in `wp_` is a single
     * character wildcard, so a database shared between `wp_` and `wpb_` installs — routine
     * on cPanel hosting, where one database often serves several sites — would pull the
     * neighbouring site's tables into this site's backup and overwrite them on restore.
     */
    protected function site_tables() {
        global $wpdb;

        $like = $wpdb->esc_like($wpdb->prefix) . '%';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared below
        $tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return is_array($tables) ? $tables : [];
    }

    /**
     * Tables whose structure is worth keeping but whose rows are not.
     *
     * Every entry here is data WordPress or WooCommerce rebuilds by itself. On a busy shop
     * these are frequently the largest tables in the database — a year of Action Scheduler
     * logs and abandoned session rows can outweigh the orders they were created for — so
     * skipping them is most of the saving for none of the risk.
     */
    protected function skips_data($table) {
        global $wpdb;

        $skip = [
            $wpdb->prefix . 'woocommerce_sessions',      // live carts
            $wpdb->prefix . 'actionscheduler_logs',      // job log, not job state
            $wpdb->prefix . 'wc_admin_notes',            // regenerated
            $wpdb->prefix . 'wc_admin_note_actions',
        ];

        /**
         * Filter tables whose rows are omitted from the dump.
         *
         * @param string[] $skip
         */
        $skip = (array) apply_filters('guardify_backup_skip_data_tables', $skip);

        return in_array($table, $skip, true);
    }

    /**
     * A WHERE clause limiting which rows of a table are dumped.
     *
     * Same reasoning as skips_data(), applied inside tables that cannot be skipped whole.
     * wp_options must survive a restore intact; the transients inside it are a cache with a
     * timestamp on it, and on a shop with a large catalogue they are routinely the majority
     * of the table by size.
     */
    protected function row_filter($table) {
        global $wpdb;

        $filters = [
            $wpdb->prefix . 'options' =>
                "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'",
            // Completed and failed actions are history; pending and in-progress ones are
            // state a restore genuinely needs.
            $wpdb->prefix . 'actionscheduler_actions' =>
                "status NOT IN ('complete','failed','canceled')",
        ];

        /**
         * Filter the per-table WHERE clauses applied to the dump.
         *
         * @param array<string,string> $filters
         */
        $filters = (array) apply_filters('guardify_backup_row_filters', $filters);

        return isset($filters[$table]) ? $filters[$table] : '';
    }

    /**
     * Primary key columns of a table, in key order.
     */
    protected function primary_key_columns($table) {
        global $wpdb;

        $keys = $wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if (empty($keys)) {
            return [];
        }

        usort($keys, function ($a, $b) {
            return (int) $a['Seq_in_index'] - (int) $b['Seq_in_index'];
        });

        return array_map(function ($k) {
            return $k['Column_name'];
        }, $keys);
    }

    /**
     * Tear down the job, acknowledge the portal, and record the outcome.
     *
     * @param array|WP_Error $result
     * @return string|WP_Error
     */
    private function finish_job($result) {
        $job = get_option(self::JOB_OPTION, null);

        if (is_array($job)) {
            if (!empty($job['path']) && file_exists($job['path'])) {
                wp_delete_file($job['path']);
            }
            if (!empty($job['ack'])) {
                $api = new Guardify_API();
                if ($api->is_connected()) {
                    $api->post('/api/v1/backup/ack', ['id' => $job['ack']]);
                }
            }
        }

        delete_option(self::JOB_OPTION);
        wp_clear_scheduled_hook('guardify_backup_worker');

        if (is_wp_error($result)) {
            update_option(self::LAST_RESULT, [
                'ok'      => false,
                'message' => $result->get_error_message(),
                'time'    => time(),
            ], false);
            error_log('Guardify Backup: ' . $result->get_error_message());
            return $result;
        }

        update_option(self::LAST_RESULT, [
            'ok'      => true,
            'message' => 'ব্যাকআপ সফলভাবে সম্পন্ন হয়েছে।',
            'rows'    => is_array($job) ? (int) $job['rows'] : 0,
            'time'    => time(),
        ], false);

        return 'done';
    }

    /**
     * Upload a finished archive to R2 via presigned URL (3-step flow).
     * Step 1: ask the engine for a presigned URL.
     * Step 2: stream the file straight to R2.
     * Step 3: confirm with the engine so the archive is recorded.
     */
    private function upload_to_engine($file_path, $note = '', $raw_size = 0) {
        $file_size = @filesize($file_path);
        if ($file_size === false || $file_size <= 0) {
            return new WP_Error('bad_file', 'ব্যাকআপ ফাইল পড়া যায়নি।');
        }

        // Routed through Guardify_API so the request is signed. Calling wp_remote_get
        // directly with only an X-GF-Key header worked while keys were bearer-authenticated
        // and fails with 401 the moment a key is in hmac mode — which would break backup and
        // restore for every signed install.
        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।');
        }

        $presign = $api->get('/api/v1/backup/presign-upload');
        if (empty($presign['url']) || empty($presign['object_key'])) {
            $message = isset($presign['error'])
                ? $presign['error']
                : esc_html__('প্রিসাইন URL পাওয়া যায়নি।', 'guardify-pro');
            return new WP_Error('presign_failed', $message);
        }

        $upload_url = $presign['url'];
        $object_key = $presign['object_key'];

        $fh = fopen($file_path, 'rb');
        if (!$fh) {
            return new WP_Error('file_open', 'ব্যাকআপ ফাইল খোলা যায়নি।');
        }

        $ch = curl_init($upload_url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $file_size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/gzip'],
        ]);

        $curl_result = curl_exec($ch);
        $curl_code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($curl_result === false || $curl_code < 200 || $curl_code >= 300) {
            $detail = $curl_error !== '' ? $curl_error : 'HTTP ' . $curl_code;
            return new WP_Error('r2_upload_failed', 'R2 আপলোড ব্যর্থ: ' . $detail);
        }

        // Guardify_API unwraps the {success, data} envelope, so a successful confirm comes
        // back as the archive record itself and a failure as {success: false, error}.
        // The checksum is computed here, after the upload, over the exact bytes on disk.
        //
        // It is what lets a restore know the archive is intact *before* it starts dropping
        // tables. hash_file streams, so this is one extra read of a file that was just
        // written and is still in the page cache — cheap next to producing it, and the
        // alternative is a restore that discovers corruption halfway through.
        $checksum = hash_file('sha256', $file_path);

        $confirm = $api->post('/api/v1/backup/confirm', [
            'object_key' => $object_key,
            'file_size'  => $file_size,
            'raw_size'   => (int) $raw_size,
            'checksum'   => $checksum === false ? '' : $checksum,
            'note'       => $note,
        ]);

        if (!is_array($confirm) || (isset($confirm['success']) && $confirm['success'] === false)) {
            $message = isset($confirm['error'])
                ? $confirm['error']
                : esc_html__('ব্যাকআপ নিশ্চিতকরণ ব্যর্থ।', 'guardify-pro');
            return new WP_Error('confirm_failed', $message);
        }

        return $confirm;
    }

    // ─── AJAX Handlers ───────────────────────────────────────────────────

    /**
     * AJAX: start a manual backup.
     *
     * Returns as soon as the job is queued. The browser polls guardify_backup_status
     * instead of holding an admin-ajax request open for the length of a database dump,
     * which on a large shop would hit the PHP timeout and report a failure for a backup
     * that was in fact still running.
     */
    public function ajax_backup_now() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $started = $this->start_backup('ম্যানুয়াল ব্যাকআপ');
        if (is_wp_error($started)) {
            wp_send_json_error($started->get_error_message());
        }

        wp_send_json_success([
            'queued'  => true,
            'message' => 'ব্যাকআপ শুরু হয়েছে। এটি পটভূমিতে চলবে।',
        ]);
    }

    /**
     * AJAX: progress of the in-flight backup.
     */
    public function ajax_backup_status() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $job = get_option(self::JOB_OPTION, null);

        if (is_array($job) && !empty($job['path'])) {
            $total = max(1, (int) $job['total']);
            $left  = count($job['queue']) + ($job['table'] === '' ? 0 : 1);
            wp_send_json_success([
                'running'  => true,
                'percent'  => (int) round((($total - $left) / $total) * 100),
                'rows'     => (int) $job['rows'],
                'table'    => (string) $job['table'],
                'message'  => 'ব্যাকআপ চলছে…',
            ]);
        }

        $last = get_option(self::LAST_RESULT, null);
        wp_send_json_success([
            'running' => false,
            'last'    => is_array($last) ? $last : null,
        ]);
    }

    /**
     * AJAX: List backups for this site.
     */
    public function ajax_backup_list() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $api    = new Guardify_API();
        $result = $api->get('/api/v1/backup/list');

        if (isset($result['backups'])) {
            wp_send_json_success($result);
        }

        wp_send_json_success(['backups' => [], 'count' => 0]);
    }

    /**
     * AJAX: Save backup schedule settings.
     */
    public function ajax_save_schedule() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }

        $enabled   = isset($_POST['guardify_backup_enabled']) && $_POST['guardify_backup_enabled'] === 'yes' ? 'yes' : 'no';
        $frequency = isset($_POST['guardify_backup_frequency']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_frequency'])) : 'daily';
        $time      = isset($_POST['guardify_backup_time']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_time'])) : '05:00';
        $timezone  = isset($_POST['guardify_backup_timezone']) ? sanitize_text_field(wp_unslash($_POST['guardify_backup_timezone'])) : 'Asia/Dhaka';

        $valid_freqs = ['every_6h', 'every_12h', 'daily', 'weekly'];
        if (!in_array($frequency, $valid_freqs, true)) {
            $frequency = 'daily';
        }
        if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
            $time = '05:00';
        }
        try {
            new DateTimeZone($timezone);
        } catch (Exception $e) {
            $timezone = 'Asia/Dhaka';
        }

        update_option('guardify_backup_enabled', $enabled);
        update_option('guardify_backup_frequency', $frequency);
        update_option('guardify_backup_time', $time);
        update_option('guardify_backup_timezone', $timezone);

        $this->schedule_backup();

        wp_send_json_success(['message' => 'ব্যাকআপ শিডিউল সেভ হয়েছে।']);
    }

    /**
     * Get schedule info for display.
     */
    public function get_schedule_info() {
        return [
            'enabled'   => get_option('guardify_backup_enabled', 'no'),
            'frequency' => get_option('guardify_backup_frequency', 'daily'),
            'time'      => get_option('guardify_backup_time', '05:00'),
            'timezone'  => get_option('guardify_backup_timezone', 'Asia/Dhaka'),
            'next_run'  => wp_next_scheduled('guardify_scheduled_backup')
                ? gmdate('Y-m-d H:i:s', wp_next_scheduled('guardify_scheduled_backup')) . ' UTC'
                : null,
        ];
    }
}
