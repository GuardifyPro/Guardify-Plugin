<?php
defined('ABSPATH') || exit;

/**
 * Guardify Domain Change — move a WordPress site to a new domain, from wp-admin.
 *
 * This is the operation merchants get wrong most often and most expensively. Changing a
 * domain is not changing a setting: WordPress writes its own URL into `siteurl` and `home`,
 * into every post's `guid`, into image `src` attributes inside post content, and into
 * serialized PHP across `wp_options` and `wp_postmeta`. Point the DNS somewhere new without
 * changing all of that and the site redirects to a domain that no longer serves it —
 * including wp-admin, so the merchant cannot get in to fix what they just broke.
 *
 * The usual answer is a search-and-replace plugin, and the usual outcome is a database with
 * broken serialized lengths: PHP reads past the end of a string, `unserialize()` returns
 * false, and WordPress silently reverts widgets, theme settings and gateway configuration
 * to defaults. It does not error. The merchant finds out days later.
 *
 * So this does not do a search-and-replace at all. It composes two things Guardify already
 * has, and the arrangement is what makes it safe:
 *
 *   1. Take a fresh backup — the existing sliced job. Fresh, not the nightly one, or the
 *      change would roll the shop back and lose every order since.
 *   2. The engine rewrites the archive from the old domain to the new one, serialization
 *      lengths and all. A full pass over hundreds of megabytes runs on Guardify's hardware,
 *      not on the merchant's shared hosting.
 *   3. Restore it in place — the existing sliced job, which builds staging tables and swaps
 *      them in with one atomic RENAME.
 *
 * Step 3 is why this is worth doing this way rather than with UPDATE statements. An
 * in-place rewrite of every row cannot be undone. This one leaves the live site serving
 * throughout, and a failure at any point before the swap costs nothing but staging tables.
 *
 * The rewritten database already carries the new domain in `siteurl` and `home`, so the
 * site is on its new address the moment the swap lands. The API key follows in the last
 * step, which is what stops the domain change from breaking the Guardify connection as a
 * parting gift.
 */
class Guardify_Domain {

    private static $instance = null;

    /** Where the change stands, so a closed browser does not strand it. */
    const STATE_OPTION = 'guardify_domain_change';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_guardify_domain_status', [$this, 'ajax_status']);
        add_action('wp_ajax_guardify_domain_start', [$this, 'ajax_start']);
        add_action('wp_ajax_guardify_domain_advance', [$this, 'ajax_advance']);
        add_action('wp_ajax_guardify_domain_cancel', [$this, 'ajax_cancel']);
    }

    /**
     * The domain WordPress currently believes it is served from.
     */
    public static function current_domain() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower((string) $host);
        return preg_replace('/^www\./', '', $host);
    }

    private function guard() {
        check_ajax_referer('guardify_nonce');
        // Deliberately stricter than the rest of the plugin. Everything else here is scoped
        // to whoever runs the shop; rewriting every URL in the database is scoped to
        // whoever owns the installation.
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('এই কাজটি করার অনুমতি আপনার নেই।', 'guardify-pro'));
        }
    }

    private function state() {
        $state = get_option(self::STATE_OPTION, null);
        return is_array($state) ? $state : null;
    }

    // ─── AJAX ────────────────────────────────────────────────────────────

    /**
     * Where the change stands, locally and on the engine.
     */
    public function ajax_status() {
        $this->guard();

        $state = $this->state();
        $api   = new Guardify_API();

        $remote = $api->is_connected() ? $api->get('/api/v1/domain/status') : [];

        wp_send_json_success([
            'current'  => self::current_domain(),
            'pending'  => isset($remote['pending']) ? (string) $remote['pending'] : '',
            'stage'    => $state ? $state['stage'] : '',
            'message'  => $state ? $this->stage_message($state['stage']) : '',
            'error'    => $state && !empty($state['error']) ? $state['error'] : '',
            'new'      => $state ? $state['new_domain'] : '',
        ]);
    }

    /**
     * Step 1: tell the engine where the site is going.
     *
     * Nothing on the site changes here. The engine records the destination so it keeps
     * accepting this plugin's requests once the site starts answering on the new domain —
     * without that, the change would strand itself halfway, with the site moved and unable
     * to say so.
     */
    public function ajax_start() {
        $this->guard();

        if ($this->state()) {
            wp_send_json_error(__('একটি ডোমেইন পরিবর্তন ইতিমধ্যে চলছে।', 'guardify-pro'));
        }

        $new = isset($_POST['new_domain']) ? sanitize_text_field(wp_unslash($_POST['new_domain'])) : '';
        $new = $this->normalize($new);

        if ($new === '') {
            wp_send_json_error(__('নতুন ডোমেইনটি লিখুন — যেমন newshop.com.bd', 'guardify-pro'));
        }
        if ($new === self::current_domain()) {
            wp_send_json_error(__('নতুন ও বর্তমান ডোমেইন একই।', 'guardify-pro'));
        }

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            wp_send_json_error(__('প্লাগইন সংযুক্ত নয়।', 'guardify-pro'));
        }
        if (Guardify_Backup::get_instance()->job_active() || Guardify_Restore::get_instance()->job_active()) {
            wp_send_json_error(__('একটি ব্যাকআপ বা রিস্টোর চলছে। শেষ হলে আবার চেষ্টা করুন।', 'guardify-pro'));
        }

        $result = $api->post('/api/v1/domain/prepare', ['new_domain' => $new]);
        if (!is_array($result) || (isset($result['success']) && $result['success'] === false)) {
            wp_send_json_error(isset($result['error']) ? $result['error'] : __('ডোমেইন পরিবর্তন শুরু করা যায়নি।', 'guardify-pro'));
        }

        // A fresh backup, not last night's. Restoring an old archive to change a domain
        // would take the shop back to that moment and lose every order since — which is
        // the sort of thing a merchant discovers by a customer ringing about a parcel that
        // no longer exists.
        $started = Guardify_Backup::get_instance()->start_backup(
            sprintf(__('ডোমেইন পরিবর্তনের আগে (%s → %s)', 'guardify-pro'), self::current_domain(), $new)
        );
        if (is_wp_error($started)) {
            $api->post('/api/v1/domain/cancel', []);
            wp_send_json_error(__('ব্যাকআপ শুরু করা যায়নি: ', 'guardify-pro') . $started->get_error_message());
        }

        update_option(self::STATE_OPTION, [
            'stage'      => 'backup',
            'old_domain' => self::current_domain(),
            'new_domain' => $new,
            'started'    => time(),
            'error'      => '',
        ], false);

        wp_send_json_success([
            'stage'   => 'backup',
            'message' => $this->stage_message('backup'),
        ]);
    }

    /**
     * Move the change on by whatever the current stage allows.
     *
     * Driven by the browser polling rather than by cron, because the merchant is sitting
     * there watching it and each stage is already a sliced background job of its own. This
     * only ever checks whether the current stage has finished and starts the next.
     */
    public function ajax_advance() {
        $this->guard();

        $state = $this->state();
        if (!$state) {
            wp_send_json_success(['stage' => '', 'done' => true]);
        }

        switch ($state['stage']) {
            case 'backup':
                $this->advance_from_backup($state);
                break;
            case 'restore':
                $this->advance_from_restore($state);
                break;
            default:
                $this->fail($state, __('অজানা ধাপ।', 'guardify-pro'));
        }

        $state = $this->state();
        wp_send_json_success([
            'stage'   => $state ? $state['stage'] : 'done',
            'done'    => $state === null,
            'message' => $state ? $this->stage_message($state['stage']) : __('ডোমেইন পরিবর্তন সম্পন্ন হয়েছে।', 'guardify-pro'),
            'error'   => $state && !empty($state['error']) ? $state['error'] : '',
            'new'     => $state ? $state['new_domain'] : '',
        ]);
    }

    /**
     * Backup finished — ask the engine to rewrite it, then start the restore.
     */
    private function advance_from_backup(array $state) {
        if (Guardify_Backup::get_instance()->job_active()) {
            return; // still running
        }

        $last = get_option(Guardify_Backup::LAST_RESULT, null);
        if (!is_array($last) || empty($last['ok'])) {
            $this->fail($state, __('ব্যাকআপ ব্যর্থ হয়েছে: ', 'guardify-pro') .
                (is_array($last) && !empty($last['message']) ? $last['message'] : __('অজানা কারণ', 'guardify-pro')));
            return;
        }

        $api = new Guardify_API();
        $list = $api->get('/api/v1/backup/list');
        if (!is_array($list) || empty($list['backups'])) {
            $this->fail($state, __('ব্যাকআপটি খুঁজে পাওয়া যায়নি।', 'guardify-pro'));
            return;
        }

        // The newest archive is the one just uploaded. The list comes back newest-first.
        $backup_id = isset($list['backups'][0]['id']) ? $list['backups'][0]['id'] : '';
        if ($backup_id === '') {
            $this->fail($state, __('ব্যাকআপটি খুঁজে পাওয়া যায়নি।', 'guardify-pro'));
            return;
        }

        $auth = $api->post('/api/v1/domain/authorise', ['backup_id' => $backup_id]);
        if (!is_array($auth) || empty($auth['token'])) {
            $this->fail($state, isset($auth['error']) ? $auth['error'] : __('ঠিকানা বদলানো ফাইল তৈরি করা যায়নি।', 'guardify-pro'));
            return;
        }

        $started = Guardify_Restore::get_instance()->start([
            'token'     => $auth['token'],
            'checksum'  => isset($auth['checksum']) ? $auth['checksum'] : '',
            'file_size' => isset($auth['file_size']) ? (int) $auth['file_size'] : 0,
        ]);
        if (is_wp_error($started)) {
            $this->fail($state, __('রিস্টোর শুরু করা যায়নি: ', 'guardify-pro') . $started->get_error_message());
            return;
        }

        $state['stage']     = 'restore';
        $state['rewritten'] = isset($auth['rewritten']) ? (int) $auth['rewritten'] : 0;
        update_option(self::STATE_OPTION, $state, false);
    }

    /**
     * Restore finished — the site is now on its new domain, so move the API key to match.
     */
    private function advance_from_restore(array $state) {
        if (Guardify_Restore::get_instance()->job_active()) {
            return;
        }

        $last = get_option(Guardify_Restore::LAST_RESULT, null);
        if (!is_array($last) || empty($last['ok'])) {
            $this->fail($state, __('রিস্টোর ব্যর্থ হয়েছে: ', 'guardify-pro') .
                (is_array($last) && !empty($last['message']) ? $last['message'] : __('অজানা কারণ', 'guardify-pro')) .
                __(' — আপনার সাইটে কোনো পরিবর্তন হয়নি।', 'guardify-pro'));
            return;
        }

        // The restored database carries the new domain in siteurl and home, so belt and
        // braces: these are set explicitly in case a site defines them in wp-config.php,
        // where the database value is overridden and the rewrite alone would not take.
        update_option('siteurl', 'https://' . $state['new_domain']);
        update_option('home', 'https://' . $state['new_domain']);

        $api = new Guardify_API();
        $api->post('/api/v1/domain/complete', []);

        delete_option(self::STATE_OPTION);
        update_option('guardify_domain_change_last', [
            'ok'   => true,
            'from' => $state['old_domain'],
            'to'   => $state['new_domain'],
            'time' => time(),
        ], false);
    }

    /**
     * Abandon a change.
     *
     * Safe at every stage the button is reachable in — the backup and the restore are both
     * jobs that leave the live site untouched until their final step, and the restore's
     * final step is a single statement that cannot be interrupted from here.
     */
    public function ajax_cancel() {
        $this->guard();

        $state = $this->state();
        $api   = new Guardify_API();
        $api->post('/api/v1/domain/cancel', []);

        // Through the restore's own abort path, so staging tables are dropped, temp files
        // removed and the engine told. Forgetting the job row instead would leave a full
        // copy of the database on the merchant's disk.
        if ($state && $state['stage'] === 'restore') {
            Guardify_Restore::get_instance()->abort(__('ডোমেইন পরিবর্তন বাতিল করা হয়েছে।', 'guardify-pro'));
        }

        delete_option(self::STATE_OPTION);
        wp_send_json_success(['message' => __('ডোমেইন পরিবর্তন বাতিল হয়েছে। সাইটে কোনো পরিবর্তন হয়নি।', 'guardify-pro')]);
    }

    // ─── internals ───────────────────────────────────────────────────────

    private function fail(array $state, $message) {
        $api = new Guardify_API();
        $api->post('/api/v1/domain/cancel', []);

        $state['error'] = $message;
        $state['stage'] = 'failed';
        update_option(self::STATE_OPTION, $state, false);

        error_log('Guardify Domain Change: ' . $message);
    }

    private function stage_message($stage) {
        $messages = [
            'backup'  => __('বর্তমান অবস্থার ব্যাকআপ নেওয়া হচ্ছে…', 'guardify-pro'),
            'restore' => __('নতুন ঠিকানা বসানো হচ্ছে (আপনার সাইট এখনো চালু আছে)…', 'guardify-pro'),
            'failed'  => __('ডোমেইন পরিবর্তন ব্যর্থ হয়েছে।', 'guardify-pro'),
        ];
        return isset($messages[$stage]) ? $messages[$stage] : '';
    }

    /**
     * Reduce whatever the merchant typed to a bare host.
     *
     * They will paste "https://newshop.com.bd/" or type "www.newshop.com.bd", and both mean
     * the same site. Rejecting either would be a wizard that fails on the most natural
     * thing to type.
     */
    protected function normalize($raw) {
        $d = strtolower(trim((string) $raw));
        $d = preg_replace('#^https?://#', '', $d);
        $d = preg_replace('#^//#', '', $d);
        $d = explode('/', $d)[0];
        $d = explode('?', $d)[0];
        $d = explode('#', $d)[0];
        // A port belongs to a local development setup, not to a shop anyone reaches.
        $d = explode(':', $d)[0];
        $d = preg_replace('/^www\./', '', $d);
        $d = trim($d, '.');

        // Only what a hostname can contain. Anything else is a typo or a paste that brought
        // something along with it, and silently stripping it would move the site somewhere
        // the merchant did not ask for.
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $d)) {
            return '';
        }

        return $d;
    }
}
