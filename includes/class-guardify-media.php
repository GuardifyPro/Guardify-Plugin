<?php
defined('ABSPATH') || exit;

/**
 * Guardify Media — backup, restore and migration of wp-content/uploads.
 *
 * The database backup was only ever half a backup. wp_posts records that an attachment lives
 * at /wp-content/uploads/2026/03/saree-red.jpg, and until now nothing put the file there: a
 * restored or migrated shop came up with every order intact and every product image missing.
 *
 * What this class does on the merchant's server is deliberately the smallest possible thing:
 * walk the uploads directory, stat each file, and upload the ones Guardify asks for. It does
 * not hash anything. It does not keep a manifest of its own to compare against. It does not
 * decide what has changed — it reports what is on disk and is told what to do about it.
 *
 * That division exists because of the arithmetic. A shop with 40,000 media files on shared
 * hosting can afford one stat() per file, which is a metadata read the filesystem answers from
 * cache. It cannot afford one SHA-256 per file, which means reading every byte of several
 * gigabytes — minutes of disk and CPU that the hosting account is throttled for, while
 * customers are browsing. So change detection is (path, size, mtime), the same quick-check
 * rsync has used for twenty years, and the comparison happens on Guardify's side where the
 * previous manifest already lives.
 *
 * Everything is sliced. The walk resumes from a directory cursor, uploads resume from a queue,
 * and a restore resumes from a path cursor, so no single PHP request has to finish anything.
 */
class Guardify_Media {

    private static $instance = null;

    /** State for the in-progress upload sync. */
    const JOB_OPTION = 'guardify_media_job';

    /** State for the in-progress restore. */
    const RESTORE_OPTION = 'guardify_media_restore';

    /** Result of the last completed sync, for the admin screen. */
    const LAST_RESULT = 'guardify_media_last_result';

    /** Held for the duration of one slice so two cron ticks cannot both walk. */
    const SLICE_LOCK = 'guardify_media_slice_lock';

    /** Seconds of wall clock one slice may use before yielding the PHP worker. */
    const SLICE_BUDGET = 15;

    /** A job that has not progressed in this long is assumed dead. */
    const JOB_MAX_AGE = 6 * HOUR_IN_SECONDS;

    /** Directory entries read per slice from one directory. */
    const SCAN_CHUNK = 400;

    /** Manifest entries sent per request. Must not exceed the engine's page size. */
    const PAGE_SIZE = 500;

    /** Seconds allowed for one file's upload before it is abandoned and retried later. */
    const UPLOAD_TIMEOUT = 120;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('guardify_media_worker', [$this, 'run_slice']);
        add_action('guardify_media_restore_worker', [$this, 'run_restore_slice']);

        // Polling for a portal-raised request rides on the backup poll's existing 5-minute
        // cron rather than adding another. It also returns immediately when the merchant has
        // not turned media sync on, so a shop that does not use the feature pays nothing for
        // it — not even one request every five minutes.
        add_action('guardify_check_pending_backup', [$this, 'poll_requests']);

        add_action('wp_ajax_guardify_media_start', [$this, 'ajax_start']);
        add_action('wp_ajax_guardify_media_status', [$this, 'ajax_status']);
        add_action('wp_ajax_guardify_media_cancel', [$this, 'ajax_cancel']);
        add_action('wp_ajax_guardify_media_restore', [$this, 'ajax_restore']);
        add_action('wp_ajax_guardify_media_save_settings', [$this, 'ajax_save_settings']);
    }

    /**
     * Whether the merchant has turned media sync on.
     *
     * Off by default, and deliberately not enabled by any onboarding preset. Uploading a
     * shop's entire media library is the single largest thing this plugin can be asked to do,
     * and it should happen because somebody chose it.
     */
    public function is_enabled() {
        return get_option('guardify_media_enabled', 'no') === 'yes';
    }

    /**
     * Absolute path to the uploads directory, with a trailing slash, or '' if unavailable.
     */
    protected function uploads_dir() {
        $info = wp_get_upload_dir();
        if (empty($info['basedir'])) {
            return '';
        }
        $real = realpath($info['basedir']);
        if ($real === false) {
            return '';
        }
        return rtrim(str_replace('\\', '/', $real), '/') . '/';
    }

    // ─── Path safety ─────────────────────────────────────────────────────

    /**
     * Whether a relative path is one this plugin will read from or write to.
     *
     * The engine validates the same rules, and both are needed. The engine's copy protects
     * what goes into storage; this copy protects what comes out of it, and the two are
     * different trust boundaries — a restore reads paths from the network and turns them into
     * filesystem writes inside a folder the web server serves.
     *
     * The failure this prevents is not subtle: a path of "../../wp-config.php" would have a
     * restore overwrite the site's database credentials, and a path ending in ".php" would
     * have it write executable code into a directory that, on most cPanel hosting, executes
     * whatever is placed there.
     */
    protected function safe_rel_path($rel) {
        if (!is_string($rel) || $rel === '') {
            return false;
        }
        // A NUL byte truncates the string inside PHP's filesystem functions, so
        // "safe.jpg\0.php" passes an extension check and is written as a .php file.
        if (strpos($rel, "\0") !== false) {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $rel)) {
            return false;
        }

        $rel = str_replace('\\', '/', $rel);
        $rel = ltrim($rel, '/');
        if ($rel === '') {
            return false;
        }
        // Windows drive letters and UNC prefixes are not relative paths.
        if (preg_match('#^[a-zA-Z]:#', $rel)) {
            return false;
        }

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '..') {
                return false;
            }
            if ($segment !== '' && rtrim($segment, '. ') !== $segment) {
                return false;
            }
        }

        if (strlen($rel) > 400) {
            return false;
        }

        return $this->allowed_extension($rel) ? $rel : false;
    }

    /**
     * Whether a filename's extensions are all non-executable.
     *
     * Every dot-separated suffix is checked, not just the last. "shell.php.jpg" ends in .jpg
     * and is an image to a naive test, but Apache configured with AddHandler — which is the
     * default on a number of Bangladeshi shared hosts — matches on any extension in the name
     * and serves it as PHP.
     */
    protected function allowed_extension($rel) {
        $blocked = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phtml', 'phar', 'pht',
            'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'dll', 'so', 'jsp', 'asp', 'aspx',
            'htaccess', 'htpasswd',
        ];

        $name  = strtolower(basename($rel));
        $parts = explode('.', $name);

        // ".htaccess" has no suffix to split off — its whole name is the dangerous part.
        if (in_array(ltrim($name, '.'), $blocked, true)) {
            return false;
        }

        for ($i = 1; $i < count($parts); $i++) {
            if (in_array($parts[$i], $blocked, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turn a relative path into an absolute one, refusing anything that escapes uploads/.
     *
     * Checked by string prefix on the resolved parent directory rather than by realpath() on
     * the file, because the file does not exist yet when a restore is about to create it.
     */
    protected function absolute_path($base, $rel) {
        $rel = $this->safe_rel_path($rel);
        if ($rel === false) {
            return false;
        }

        $path = $base . $rel;

        // A symlinked subdirectory could still point outside the tree. Resolving the parent —
        // which does exist by the time this is called for a write — catches that.
        $parent = dirname($path);
        $real   = realpath($parent);
        if ($real !== false) {
            $real = rtrim(str_replace('\\', '/', $real), '/') . '/';
            if (strpos($real, $base) !== 0) {
                return false;
            }
        }

        return $path;
    }

    // ─── Sync (upload) ───────────────────────────────────────────────────

    /**
     * Whether a sync is in flight.
     */
    public function job_active() {
        $job = get_option(self::JOB_OPTION, null);
        if (!is_array($job) || empty($job['sync_id'])) {
            return false;
        }
        // A worker that died — a fatal, a host restart mid-slice — would otherwise block
        // every future sync forever, which presents as a feature that worked once.
        if (time() - (int) $job['touched'] > self::JOB_MAX_AGE) {
            $this->finish_job(new WP_Error('stalled', 'মিডিয়া সিঙ্কের সময়সীমা পার হয়েছে।'));
            return false;
        }
        return true;
    }

    /**
     * Poll the engine for a media sync raised from the Guardify dashboard.
     */
    public function poll_requests() {
        if (!$this->is_enabled() || $this->job_active() || $this->restore_active()) {
            return;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return;
        }

        $status = $api->get('/api/v1/media/status');
        if (!is_array($status) || empty($status['active']) || !is_array($status['active'])) {
            return;
        }

        // Only a request the portal raised and nobody has started. A row already 'running'
        // belongs to a job on this site that this method must not restart.
        if (($status['active']['status'] ?? '') !== 'requested') {
            return;
        }

        $started = $this->start_sync('Guardify ড্যাশবোর্ড থেকে অনুরোধ');
        if (is_wp_error($started)) {
            error_log('Guardify Media: ' . $started->get_error_message());
        }
    }

    /**
     * Open a sync and kick the worker.
     *
     * @return true|WP_Error
     */
    public function start_sync($note = '', $spawn = true) {
        if (!$this->is_enabled()) {
            return new WP_Error('disabled', 'মিডিয়া ব্যাকআপ চালু নেই।');
        }
        if ($this->job_active()) {
            return new WP_Error('already_running', 'আরেকটি মিডিয়া সিঙ্ক চলছে।');
        }
        if ($this->restore_active()) {
            return new WP_Error('restore_running', 'মিডিয়া রিস্টোর চলছে — শেষ হওয়া পর্যন্ত অপেক্ষা করুন।');
        }

        $base = $this->uploads_dir();
        if ($base === '') {
            return new WP_Error('no_uploads', 'আপলোড ফোল্ডার পাওয়া যায়নি।');
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।');
        }

        $started = $api->post('/api/v1/media/sync/start', ['note' => (string) $note]);
        if (!is_array($started) || empty($started['sync_id'])) {
            $message = isset($started['error'])
                ? $started['error']
                : 'মিডিয়া সিঙ্ক শুরু করা যায়নি।';
            return new WP_Error('start_failed', $message);
        }

        update_option(self::JOB_OPTION, [
            'sync_id'   => sanitize_text_field($started['sync_id']),
            'base'      => $base,
            // The walk is a queue of directories plus a position inside the current one.
            // Storing the queue rather than a recursion state is what makes it resumable
            // across separate PHP requests.
            'dirs'      => [''],
            'dir'       => null,
            'pos'       => 0,
            'batch'     => [],
            'queue'     => [],
            'excluded'  => isset($started['excluded_dirs']) && is_array($started['excluded_dirs'])
                ? array_map('strval', $started['excluded_dirs'])
                : [],
            'max_file'  => isset($started['max_file_size']) ? (int) $started['max_file_size'] : 67108864,
            'seen'      => 0,
            'uploaded'  => 0,
            'skipped'   => 0,
            'bytes'     => 0,
            'walk_done' => false,
            'started'   => time(),
            'touched'   => time(),
        ], false);

        delete_option(self::LAST_RESULT);
        if ($spawn) {
            $this->schedule_worker();
        }

        return true;
    }

    private function schedule_worker($delay = 0) {
        if (!wp_next_scheduled('guardify_media_worker')) {
            wp_schedule_single_event(time() + $delay, 'guardify_media_worker');
        }
        if ($delay === 0 && function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Take one bounded slice of the sync: walk a little, then upload a little.
     *
     * Walking and uploading share the slice rather than running as separate stages, so a shop
     * begins uploading its first files seconds after the sync starts instead of after the
     * whole directory tree has been enumerated. On a large library that is the difference
     * between visible progress and a screen that says nothing is happening for ten minutes.
     */
    public function run_slice() {
        if (!$this->job_active()) {
            return 'idle';
        }

        if (get_transient(self::SLICE_LOCK)) {
            $this->schedule_worker(60);
            return 'working';
        }
        set_transient(self::SLICE_LOCK, 1, 5 * MINUTE_IN_SECONDS);

        $job = get_option(self::JOB_OPTION);
        $api = new Guardify_API();

        if (!$api->is_connected()) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_job(new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।'));
        }

        $budget   = (int) apply_filters('guardify_media_slice_budget', self::SLICE_BUDGET);
        $deadline = microtime(true) + max(3, $budget);

        // Uploads first. A queue left over from the previous slice is holding presigned URLs
        // that expire, so it is worth more than more walking.
        $result = $this->drain_queue($api, $job, $deadline);
        if (is_wp_error($result)) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_job($result);
        }

        while (microtime(true) < $deadline && !$job['walk_done'] && empty($job['queue'])) {
            $this->walk_step($job);

            if (count($job['batch']) >= self::PAGE_SIZE || ($job['walk_done'] && !empty($job['batch']))) {
                $sent = $this->send_page($api, $job);
                if (is_wp_error($sent)) {
                    delete_transient(self::SLICE_LOCK);
                    return $this->finish_job($sent);
                }
                // Uploading happens on the next pass through the loop, or the next slice.
                break;
            }
        }

        // Anything the page handed back gets uploaded with whatever budget is left.
        $result = $this->drain_queue($api, $job, $deadline);
        if (is_wp_error($result)) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_job($result);
        }

        $job['touched'] = time();

        $done = $job['walk_done'] && empty($job['batch']) && empty($job['queue']);
        if (!$done) {
            update_option(self::JOB_OPTION, $job, false);
            delete_transient(self::SLICE_LOCK);
            $this->schedule_worker(20);
            return 'working';
        }

        // The walk covered the whole tree, which is what lets the engine prune files the
        // merchant has deleted. A partial walk must never claim this: pruning on a partial
        // walk deletes everything the walk had not reached.
        $completed = $api->post('/api/v1/media/sync/complete', [
            'sync_id' => $job['sync_id'],
            'full'    => true,
        ]);

        update_option(self::JOB_OPTION, $job, false);
        delete_transient(self::SLICE_LOCK);

        if (!is_array($completed)) {
            return $this->finish_job(new WP_Error('complete_failed', 'সিঙ্ক শেষ করা নিশ্চিত হয়নি।'));
        }

        return $this->finish_job(true);
    }

    /**
     * Advance the directory walk by up to SCAN_CHUNK entries.
     */
    protected function walk_step(array &$job) {
        $base = $job['base'];

        if ($job['dir'] === null) {
            if (empty($job['dirs'])) {
                $job['walk_done'] = true;
                return;
            }
            $job['dir'] = array_shift($job['dirs']);
            $job['pos'] = 0;
        }

        $dir_path = $base . ($job['dir'] === '' ? '' : $job['dir'] . '/');
        $handle   = @opendir($dir_path);
        if (!$handle) {
            // An unreadable directory is skipped rather than fatal. Permissions inside
            // uploads/ are frequently inconsistent on shared hosting, and one bad folder must
            // not stop the other 39,000 files from being backed up.
            $job['dir'] = null;
            return;
        }

        // Resuming means skipping the entries already handled. readdir order is stable for an
        // unchanged directory, and skipping costs no stat() — it is the cheapest resumable
        // cursor available for a directory that may hold tens of thousands of files, where
        // reading every name into an option would be the real problem.
        $skipped = 0;
        while ($skipped < $job['pos'] && ($entry = readdir($handle)) !== false) {
            $skipped++;
        }

        $handled = 0;
        while ($handled < self::SCAN_CHUNK && ($entry = readdir($handle)) !== false) {
            $handled++;

            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $rel  = $job['dir'] === '' ? $entry : $job['dir'] . '/' . $entry;
            $full = $dir_path . $entry;

            if (is_dir($full)) {
                // A symlinked directory is not followed. Following one can leave the uploads
                // tree entirely — a link to /home or to the WordPress root is enough — and can
                // also loop forever if it points at an ancestor.
                if (is_link($full)) {
                    continue;
                }
                if ($this->dir_excluded($rel, $job['excluded'])) {
                    continue;
                }
                $job['dirs'][] = $rel;
                continue;
            }

            if (!is_file($full) || is_link($full)) {
                continue;
            }

            if ($this->safe_rel_path($rel) === false) {
                $job['skipped']++;
                continue;
            }
            if ($this->is_excluded($rel, $job['excluded'])) {
                continue;
            }

            $size = @filesize($full);
            if ($size === false) {
                $job['skipped']++;
                continue;
            }
            if ($size > $job['max_file']) {
                $job['skipped']++;
                continue;
            }

            $mtime = @filemtime($full);

            $job['batch'][] = [
                'path'  => $rel,
                'size'  => (int) $size,
                'mtime' => $mtime === false ? 0 : (int) $mtime,
            ];
        }

        $job['pos'] += $handled;
        closedir($handle);

        if ($handled < self::SCAN_CHUNK) {
            $job['dir'] = null;
            if (empty($job['dirs'])) {
                $job['walk_done'] = true;
            }
        }
    }

    /**
     * Whether a path falls inside a directory the engine told us to skip.
     *
     * Matched on whole path segments. A substring test would skip a product photograph named
     * "cache-cleaner.jpg", and the merchant would never learn that image was not backed up.
     */
    protected function is_excluded($rel, array $excluded) {
        // The last segment of a file path is its name, and only directories are matched — a
        // product image legitimately called "tmp.jpg" must not be skipped because "tmp" is an
        // excluded folder.
        $segments = explode('/', $rel);
        array_pop($segments);

        return $this->segments_excluded($segments, $excluded);
    }

    /**
     * Whether a directory itself is one to skip, so its whole subtree is never even opened.
     *
     * Checked before descending rather than per file inside it. A cache folder can hold tens of
     * thousands of generated thumbnails, and the cheapest way to not back them up is to never
     * read the directory at all.
     */
    protected function dir_excluded($rel, array $excluded) {
        return $this->segments_excluded(explode('/', $rel), $excluded);
    }

    /**
     * Whether any directory segment, or any adjacent pair of them, is excluded.
     *
     * Pairs are matched so a two-part entry like "elementor/css" can exclude generated CSS
     * without excluding "elementor/fonts" beside it.
     */
    protected function segments_excluded(array $segments, array $excluded) {
        if (empty($excluded) || empty($segments)) {
            return false;
        }

        $set = [];
        foreach ($excluded as $dir) {
            $dir = strtolower(trim(trim($dir), '/'));
            if ($dir !== '') {
                $set[$dir] = true;
            }
        }

        $count = count($segments);
        for ($i = 0; $i < $count; $i++) {
            $segment = strtolower($segments[$i]);
            if (isset($set[$segment])) {
                return true;
            }
            if ($i + 1 < $count && isset($set[$segment . '/' . strtolower($segments[$i + 1])])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send the accumulated manifest page and queue whatever the engine asks for.
     *
     * @return true|WP_Error
     */
    protected function send_page(Guardify_API $api, array &$job) {
        if (empty($job['batch'])) {
            return true;
        }

        $page = $api->post('/api/v1/media/sync/page', [
            'sync_id' => $job['sync_id'],
            'files'   => array_values($job['batch']),
        ]);

        if (!is_array($page) || (isset($page['success']) && $page['success'] === false)) {
            $message = isset($page['error']) ? $page['error'] : 'ম্যানিফেস্ট পাঠানো যায়নি।';
            return new WP_Error('page_failed', $message);
        }

        $job['seen'] += count($job['batch']);
        $job['batch'] = [];

        if (!empty($page['skipped']) && is_array($page['skipped'])) {
            $job['skipped'] += count($page['skipped']);
            // The first few reasons are kept for the admin screen. A merchant whose media
            // backup is incomplete needs to be able to see why without reading a log.
            $notes = get_option('guardify_media_skips', []);
            if (!is_array($notes)) {
                $notes = [];
            }
            foreach ($page['skipped'] as $skip) {
                if (count($notes) >= 20) {
                    break;
                }
                $notes[] = [
                    'path'   => isset($skip['path']) ? (string) $skip['path'] : '',
                    'reason' => isset($skip['reason']) ? (string) $skip['reason'] : '',
                ];
            }
            update_option('guardify_media_skips', $notes, false);
        }

        if (!empty($page['upload']) && is_array($page['upload'])) {
            foreach ($page['upload'] as $target) {
                if (empty($target['path']) || empty($target['url'])) {
                    continue;
                }
                $job['queue'][] = [
                    'path' => (string) $target['path'],
                    'url'  => (string) $target['url'],
                ];
            }
        }

        return true;
    }

    /**
     * Upload queued files until the slice budget runs out.
     *
     * @return true|WP_Error
     */
    protected function drain_queue(Guardify_API $api, array &$job, $deadline) {
        if (empty($job['queue'])) {
            return true;
        }

        $confirmed = [];
        $bytes     = 0;

        while (!empty($job['queue']) && microtime(true) < $deadline) {
            $item = array_shift($job['queue']);

            $rel = $this->safe_rel_path($item['path']);
            if ($rel === false) {
                $job['skipped']++;
                continue;
            }

            $full = $job['base'] . $rel;
            if (!is_file($full)) {
                // Deleted between the walk and the upload. Not an error: the next sync will
                // report it gone and the engine will prune it.
                continue;
            }

            $size = @filesize($full);
            if ($size === false) {
                $job['skipped']++;
                continue;
            }

            if ($this->put_file($item['url'], $full, $size)) {
                $confirmed[] = $rel;
                $bytes      += (int) $size;
            } else {
                // A failed upload is left for the next sync rather than retried immediately.
                // The commonest cause is a saturated uplink, and retrying into that makes it
                // worse; the file's pending row is swept on Guardify's side and it is offered
                // again next time.
                $job['skipped']++;
            }
        }

        if (!empty($confirmed)) {
            $ack = $api->post('/api/v1/media/sync/uploaded', [
                'sync_id' => $job['sync_id'],
                'paths'   => $confirmed,
            ]);

            if (!is_array($ack) || (isset($ack['success']) && $ack['success'] === false)) {
                // The bytes are in R2 but unconfirmed. Their rows stay pending and the files
                // are offered again on the next sync, which is a wasted upload rather than a
                // missing file — the right way round for a failure of this kind.
                return new WP_Error('confirm_failed', 'আপলোড নিশ্চিত করা যায়নি।');
            }

            $job['uploaded'] += count($confirmed);
            $job['bytes']    += $bytes;
        }

        return true;
    }

    /**
     * PUT one file straight to R2 from disk.
     *
     * Streamed from a file handle rather than read into a string. file_get_contents on a 60MB
     * image would allocate 60MB inside a PHP process whose memory_limit is commonly 128MB on
     * the hosting this runs on, with WordPress and WooCommerce already in it.
     */
    protected function put_file($url, $path, $size) {
        if (!function_exists('curl_init')) {
            return false;
        }

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return false;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::UPLOAD_TIMEOUT,
            CURLOPT_HTTPHEADER     => ['Content-Type: ' . $this->content_type($path)],
        ]);

        $ok   = curl_exec($ch) !== false;
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        return $ok && $code >= 200 && $code < 300;
    }

    /**
     * Content type for the presigned PUT. Must match what the engine signed the URL with, or
     * R2 rejects the upload with a signature mismatch.
     */
    protected function content_type($path) {
        $map = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
            'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'zip' => 'application/zip', 'woff2' => 'font/woff2',
            'css' => 'text/css',
        ];

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset($map[$ext]) ? $map[$ext] : 'application/octet-stream';
    }

    /**
     * Tear down the sync job and record the outcome.
     *
     * @param true|WP_Error $result
     * @return string|WP_Error
     */
    private function finish_job($result) {
        $job = get_option(self::JOB_OPTION, null);

        if (is_wp_error($result) && is_array($job) && !empty($job['sync_id'])) {
            // Told rather than left running. An abandoned sync holds the one-per-site lock on
            // Guardify's side until it ages out, which would make the next attempt fail for a
            // reason the merchant cannot see.
            $api = new Guardify_API();
            if ($api->is_connected()) {
                $api->post('/api/v1/media/sync/complete', [
                    'sync_id' => $job['sync_id'],
                    'error'   => $result->get_error_message(),
                ]);
            }
        }

        delete_option(self::JOB_OPTION);
        wp_clear_scheduled_hook('guardify_media_worker');

        // The one event that changes stored usage has just happened, so the cached figure is
        // dropped rather than left to show a stale number for another five minutes.
        delete_transient('guardify_media_usage');

        if (is_wp_error($result)) {
            update_option(self::LAST_RESULT, [
                'ok'      => false,
                'message' => $result->get_error_message(),
                'time'    => time(),
            ], false);
            error_log('Guardify Media: ' . $result->get_error_message());
            return $result;
        }

        update_option(self::LAST_RESULT, [
            'ok'       => true,
            'message'  => 'মিডিয়া ব্যাকআপ সম্পন্ন হয়েছে।',
            'seen'     => is_array($job) ? (int) $job['seen'] : 0,
            'uploaded' => is_array($job) ? (int) $job['uploaded'] : 0,
            'skipped'  => is_array($job) ? (int) $job['skipped'] : 0,
            'bytes'    => is_array($job) ? (int) $job['bytes'] : 0,
            'time'     => time(),
        ], false);

        return 'done';
    }

    // ─── Restore ─────────────────────────────────────────────────────────

    public function restore_active() {
        $job = get_option(self::RESTORE_OPTION, null);
        if (!is_array($job)) {
            return false;
        }
        if (time() - (int) $job['touched'] > self::JOB_MAX_AGE) {
            delete_option(self::RESTORE_OPTION);
            wp_clear_scheduled_hook('guardify_media_restore_worker');
            return false;
        }
        return true;
    }

    /**
     * Start pulling stored media back onto disk.
     *
     * @return true|WP_Error
     */
    public function start_restore() {
        if ($this->restore_active()) {
            return new WP_Error('already_running', 'মিডিয়া রিস্টোর ইতিমধ্যে চলছে।');
        }
        if ($this->job_active()) {
            return new WP_Error('sync_running', 'মিডিয়া সিঙ্ক চলছে — শেষ হওয়া পর্যন্ত অপেক্ষা করুন।');
        }

        $base = $this->uploads_dir();
        if ($base === '') {
            return new WP_Error('no_uploads', 'আপলোড ফোল্ডার পাওয়া যায়নি।');
        }
        if (!wp_is_writable($base)) {
            return new WP_Error('not_writable', 'আপলোড ফোল্ডারে লেখার অনুমতি নেই।');
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।');
        }

        update_option(self::RESTORE_OPTION, [
            'base'    => $base,
            'cursor'  => '',
            'written' => 0,
            'present' => 0,
            'failed'  => 0,
            'done'    => false,
            'started' => time(),
            'touched' => time(),
        ], false);

        if (!wp_next_scheduled('guardify_media_restore_worker')) {
            wp_schedule_single_event(time(), 'guardify_media_restore_worker');
        }
        if (function_exists('spawn_cron')) {
            spawn_cron();
        }

        return true;
    }

    /**
     * Download one bounded slice of the stored media.
     *
     * Nothing is deleted and nothing already correct is re-downloaded: a file whose size
     * already matches is left alone. That makes a restore safe to run on a live site and cheap
     * to run twice, which matters because the commonest reason to run it is that the first
     * attempt was interrupted.
     */
    public function run_restore_slice() {
        if (!$this->restore_active()) {
            return 'idle';
        }

        if (get_transient(self::SLICE_LOCK)) {
            wp_schedule_single_event(time() + 60, 'guardify_media_restore_worker');
            return 'working';
        }
        set_transient(self::SLICE_LOCK, 1, 5 * MINUTE_IN_SECONDS);

        $job = get_option(self::RESTORE_OPTION);
        $api = new Guardify_API();

        if (!$api->is_connected()) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_restore(new WP_Error('not_connected', 'প্লাগইন সংযুক্ত নয়।'));
        }

        $budget   = (int) apply_filters('guardify_media_slice_budget', self::SLICE_BUDGET);
        $deadline = microtime(true) + max(3, $budget);

        $page = $api->get('/api/v1/media/restore/page', [
            'after' => $job['cursor'],
            'limit' => 100,
        ]);

        if (!is_array($page) || !isset($page['files'])) {
            delete_transient(self::SLICE_LOCK);
            return $this->finish_restore(new WP_Error('page_failed', 'মিডিয়া তালিকা পাওয়া যায়নি।'));
        }

        $handled   = 0;
        $available = is_array($page['files']) ? count($page['files']) : 0;

        foreach ($page['files'] as $file) {
            if (microtime(true) >= $deadline) {
                // Whatever is left of this page comes back on the next slice: the cursor is
                // only advanced past files actually dealt with, so re-requesting the page
                // returns the remainder rather than repeating what is already on disk.
                break;
            }
            $handled++;

            if (empty($file['path']) || empty($file['url'])) {
                continue;
            }

            $rel = $this->safe_rel_path($file['path']);
            if ($rel === false) {
                $job['failed']++;
                continue;
            }

            $target = $this->absolute_path($job['base'], $rel);
            if ($target === false) {
                $job['failed']++;
                continue;
            }

            $size = isset($file['size']) ? (int) $file['size'] : 0;

            if (is_file($target) && @filesize($target) === $size) {
                $job['present']++;
                $job['cursor'] = $rel;
                continue;
            }

            if ($this->fetch_file($file['url'], $target, isset($file['mtime']) ? (int) $file['mtime'] : 0)) {
                $job['written']++;
            } else {
                $job['failed']++;
            }

            $job['cursor'] = $rel;
        }

        // Finished only when this page held nothing, or when the whole page was dealt with and
        // the engine says there is no page after it.
        //
        // Both halves are needed. Treating an empty next_cursor alone as the end would declare
        // the restore complete when the slice ran out of budget partway through the last page,
        // leaving files the merchant is told were restored. Requiring the page to have been
        // fully handled is what makes the cursor and the completion claim agree.
        if ($available === 0 || ($handled >= $available && empty($page['next_cursor']))) {
            $job['done'] = true;
        }

        $job['touched'] = time();
        update_option(self::RESTORE_OPTION, $job, false);
        delete_transient(self::SLICE_LOCK);

        if (!$job['done']) {
            wp_schedule_single_event(time() + 10, 'guardify_media_restore_worker');
            return 'working';
        }

        return $this->finish_restore(true);
    }

    /**
     * Download one file to a temporary path and move it into place.
     *
     * Written to a temporary file in the same directory and renamed, so a file that is
     * currently being served never appears half-written. rename() within one filesystem is
     * atomic, and the temp file shares the destination's directory precisely so it is.
     *
     * The stored modification time is restored too. Without it every restored file would be
     * dated "now", and since change detection is (path, size, mtime), the next sync would
     * decide the entire media library had changed and upload all of it again.
     */
    protected function fetch_file($url, $target, $mtime) {
        $dir = dirname($target);
        if (!wp_mkdir_p($dir)) {
            return false;
        }

        $tmp = $dir . '/.guardify-tmp-' . wp_generate_password(8, false, false);

        $response = wp_remote_get($url, [
            'timeout'  => self::UPLOAD_TIMEOUT,
            'stream'   => true,
            'filename' => $tmp,
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return false;
        }

        if (!@rename($tmp, $target)) {
            if (file_exists($tmp)) {
                wp_delete_file($tmp);
            }
            return false;
        }

        if ($mtime > 0) {
            @touch($target, $mtime);
        }

        return true;
    }

    /**
     * @param true|WP_Error $result
     */
    private function finish_restore($result) {
        $job = get_option(self::RESTORE_OPTION, null);

        delete_option(self::RESTORE_OPTION);
        wp_clear_scheduled_hook('guardify_media_restore_worker');

        if (is_wp_error($result)) {
            update_option(self::LAST_RESULT, [
                'ok'      => false,
                'message' => $result->get_error_message(),
                'time'    => time(),
            ], false);
            error_log('Guardify Media Restore: ' . $result->get_error_message());
            return $result;
        }

        $written = is_array($job) ? (int) $job['written'] : 0;
        $failed  = is_array($job) ? (int) $job['failed'] : 0;

        update_option(self::LAST_RESULT, [
            'ok'      => $failed === 0,
            'message' => $failed === 0
                ? 'মিডিয়া রিস্টোর সম্পন্ন হয়েছে।'
                : $failed . 'টি ফাইল নামানো যায়নি — আবার চালালে বাকিগুলো নামবে।',
            'written' => $written,
            'present' => is_array($job) ? (int) $job['present'] : 0,
            'failed'  => $failed,
            'time'    => time(),
        ], false);

        return 'done';
    }

    // ─── AJAX ────────────────────────────────────────────────────────────

    /**
     * Media operations are gated on manage_options rather than the manage_woocommerce the
     * rest of the plugin uses. A restore writes thousands of files into a directory the web
     * server serves; that is not shop work.
     */
    protected function guard() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
    }

    public function ajax_start() {
        $this->guard();

        delete_option('guardify_media_skips');

        $started = $this->start_sync('ম্যানুয়াল মিডিয়া ব্যাকআপ');
        if (is_wp_error($started)) {
            wp_send_json_error($started->get_error_message());
        }

        wp_send_json_success([
            'queued'  => true,
            'message' => 'মিডিয়া ব্যাকআপ শুরু হয়েছে। এটি পটভূমিতে চলবে।',
        ]);
    }

    public function ajax_restore() {
        $this->guard();

        $started = $this->start_restore();
        if (is_wp_error($started)) {
            wp_send_json_error($started->get_error_message());
        }

        wp_send_json_success([
            'queued'  => true,
            'message' => 'মিডিয়া রিস্টোর শুরু হয়েছে। বর্তমান ফাইলগুলো মুছে ফেলা হবে না।',
        ]);
    }

    public function ajax_cancel() {
        $this->guard();

        if ($this->job_active()) {
            $this->finish_job(new WP_Error('cancelled', 'মিডিয়া সিঙ্ক বাতিল করা হয়েছে।'));
        } elseif ($this->restore_active()) {
            $this->finish_restore(new WP_Error('cancelled', 'মিডিয়া রিস্টোর বাতিল করা হয়েছে।'));
        }

        wp_send_json_success(['message' => 'বাতিল করা হয়েছে।']);
    }

    public function ajax_status() {
        $this->guard();

        $job     = get_option(self::JOB_OPTION, null);
        $restore = get_option(self::RESTORE_OPTION, null);

        if (is_array($job)) {
            wp_send_json_success([
                'state'    => 'syncing',
                'seen'     => (int) $job['seen'],
                'uploaded' => (int) $job['uploaded'],
                'skipped'  => (int) $job['skipped'],
                'bytes'    => (int) $job['bytes'],
                'walking'  => !$job['walk_done'],
                'message'  => $job['walk_done']
                    ? 'ফাইল আপলোড হচ্ছে…'
                    : 'ফাইল খোঁজা ও আপলোড হচ্ছে…',
            ]);
        }

        if (is_array($restore)) {
            wp_send_json_success([
                'state'   => 'restoring',
                'written' => (int) $restore['written'],
                'present' => (int) $restore['present'],
                'failed'  => (int) $restore['failed'],
                'message' => 'মিডিয়া ফাইল নামানো হচ্ছে…',
            ]);
        }

        $last  = get_option(self::LAST_RESULT, null);
        $skips = get_option('guardify_media_skips', []);

        wp_send_json_success([
            'state'   => 'idle',
            'enabled' => $this->is_enabled(),
            'last'    => is_array($last) ? $last : null,
            'skips'   => is_array($skips) ? $skips : [],
            'usage'   => $this->usage(),
        ]);
    }

    /**
     * How much of the plan's storage this site is using, for the badge on the backup page.
     *
     * Cached for five minutes. The figure only changes when a sync runs, and the alternative —
     * asking the engine on every status poll — would mean a signed request every five seconds
     * while a sync is in progress, which is a lot of traffic to render a number that has not
     * moved. The poll while syncing does not read this at all.
     *
     * @return array|null
     */
    protected function usage() {
        $cached = get_transient('guardify_media_usage');
        if (is_array($cached)) {
            return $cached;
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            return null;
        }

        $status = $api->get('/api/v1/media/status');
        if (!is_array($status) || empty($status['usage']) || !is_array($status['usage'])) {
            // A plan without media sync answers 402 here. Cached as "unavailable" for a short
            // while so a free site does not ask again on every page load.
            set_transient('guardify_media_usage', ['enabled' => false], 5 * MINUTE_IN_SECONDS);
            return ['enabled' => false];
        }

        $usage = [
            'enabled'   => true,
            'files'     => isset($status['usage']['files']) ? (int) $status['usage']['files'] : 0,
            'bytes'     => isset($status['usage']['bytes']) ? (int) $status['usage']['bytes'] : 0,
            'ceiling'   => isset($status['usage']['ceiling_bytes']) ? (int) $status['usage']['ceiling_bytes'] : 0,
            'remaining' => isset($status['remaining_bytes']) ? (int) $status['remaining_bytes'] : 0,
        ];

        set_transient('guardify_media_usage', $usage, 5 * MINUTE_IN_SECONDS);

        return $usage;
    }

    public function ajax_save_settings() {
        $this->guard();

        $enabled = isset($_POST['guardify_media_enabled']) && $_POST['guardify_media_enabled'] === 'yes'
            ? 'yes'
            : 'no';

        update_option('guardify_media_enabled', $enabled);

        wp_send_json_success([
            'enabled' => $enabled === 'yes',
            'message' => $enabled === 'yes'
                ? 'মিডিয়া ব্যাকআপ চালু হয়েছে।'
                : 'মিডিয়া ব্যাকআপ বন্ধ হয়েছে।',
        ]);
    }
}
