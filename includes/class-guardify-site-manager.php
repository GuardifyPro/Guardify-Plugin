<?php
defined('ABSPATH') || exit;

/**
 * Guardify Site Manager — running the WordPress site from the Guardify dashboard.
 *
 * A merchant with three shops keeps three sets of wp-admin credentials, and in practice logs
 * into none of them until something breaks. Plugins go six months without an update, a
 * dismissed employee keeps an administrator account, and the first anyone notices is when the
 * site is defaced. This is the part of the product that answers "what is actually running on
 * my shops, and who can get into them" from one screen.
 *
 * Everything here is more dangerous than the rest of the plugin put together. Installing an
 * update is running code; deleting a user is destroying data; ending a session is locking
 * somebody out. So the design is built around a few refusals:
 *
 *   - **Off by default.** A merchant turns it on knowing what it does. A fraud plugin that
 *     silently gains the ability to install code is not a fraud plugin.
 *
 *   - **The site pulls; Guardify never pushes.** There is no endpoint on the merchant's
 *     server that performs an action. The site asks "is there anything for me", over its own
 *     signed outbound request, and the answer is a queue entry the account owner created in
 *     the dashboard. Nothing that arrives unsolicited can do anything, because nothing
 *     unsolicited is listened for.
 *
 *   - **Nothing destructive without an explicit further opt-in.** Updates and ending sessions
 *     are reversible or harmless. Deleting a user is neither, and is not offered here at all.
 *
 *   - **The last administrator cannot be locked out.** Whatever the queue says.
 */
class Guardify_Site_Manager {

    private static $instance = null;

    /** Master switch. Off until a merchant turns it on. */
    const OPTION_ENABLED = 'guardify_site_manager_enabled';

    /** Whether Guardify may install updates, as against only reporting them. */
    const OPTION_ALLOW_UPDATES = 'guardify_site_manager_updates';

    /** Cron hook that polls for queued work. */
    const CRON_HOOK = 'guardify_site_manager_poll';

    /** Held while a task runs so two cron ticks cannot install the same update twice. */
    const TASK_LOCK = 'guardify_site_task_lock';

    /** Inventory is resent at most this often, unless something changed. */
    const INVENTORY_TTL = 6 * HOUR_IN_SECONDS;

    /** Wall clock one poll may spend applying updates. */
    const TASK_BUDGET = 60;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_guardify_site_manager_save', [$this, 'ajax_save_settings']);
        add_action('wp_ajax_guardify_site_manager_status', [$this, 'ajax_status']);

        if (!$this->is_enabled()) {
            return;
        }

        add_action(self::CRON_HOOK, [$this, 'run_poll']);

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Hourly. The inventory changes when somebody installs something, which is not
            // often, and a queued update waiting up to an hour is the right trade against a
            // loopback request every few minutes on shared hosting.
            wp_schedule_event(time() + 300, 'hourly', self::CRON_HOOK);
        }
    }

    public function is_enabled() {
        return get_option(self::OPTION_ENABLED, 'no') === 'yes';
    }

    /**
     * Whether Guardify may actually install anything.
     *
     * Separate from the master switch on purpose. Plenty of merchants want to *see* what is
     * out of date across their shops without handing over the ability to change it, and that
     * is a perfectly coherent position to hold.
     */
    public function updates_allowed() {
        return $this->is_enabled() && get_option(self::OPTION_ALLOW_UPDATES, 'no') === 'yes';
    }

    // ─── Inventory ───────────────────────────────────────────────────────

    /**
     * What is installed on this site, and what is out of date.
     *
     * Read from the update transients WordPress already maintains rather than by asking
     * wordpress.org. Core refreshes those on its own schedule; querying them again here would
     * mean an outbound HTTP request per poll for information the site already has.
     */
    public function collect_inventory() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates  = get_site_transient('update_themes');
        $core_updates   = get_site_transient('update_core');

        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $available = isset($plugin_updates->response[$file]->new_version)
                ? $plugin_updates->response[$file]->new_version
                : '';

            $plugins[] = [
                'file'      => $file,
                'name'      => isset($data['Name']) ? $data['Name'] : $file,
                'version'   => isset($data['Version']) ? $data['Version'] : '',
                'available' => $available,
                'active'    => is_plugin_active($file),
            ];
        }

        $themes = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $available = isset($theme_updates->response[$slug]['new_version'])
                ? $theme_updates->response[$slug]['new_version']
                : '';

            $themes[] = [
                'slug'      => $slug,
                'name'      => $theme->get('Name'),
                'version'   => $theme->get('Version'),
                'available' => $available,
                'active'    => get_stylesheet() === $slug,
            ];
        }

        $core_available = '';
        if (isset($core_updates->updates[0]) && $core_updates->updates[0]->response === 'upgrade') {
            $core_available = $core_updates->updates[0]->current;
        }

        return [
            'wp_version'     => get_bloginfo('version'),
            'wp_available'   => $core_available,
            'php_version'    => PHP_VERSION,
            'wc_version'     => defined('WC_VERSION') ? WC_VERSION : '',
            'plugin_version' => GUARDIFY_VERSION,
            'multisite'      => is_multisite(),
            'plugins'        => $plugins,
            'themes'         => $themes,
            'users'          => $this->collect_users(),
            'updates_allowed' => $this->updates_allowed(),
        ];
    }

    /**
     * The accounts that can get into this site.
     *
     * Only those with a role that can do damage. A shop with four thousand customer accounts
     * would otherwise send four thousand rows every six hours to say nothing — and a customer
     * account is not what a merchant is scanning this list for. They are looking for the
     * administrator they forgot about.
     */
    protected function collect_users() {
        $users = get_users([
            'role__in' => ['administrator', 'editor', 'shop_manager'],
            'number'   => 100,
            'fields'   => ['ID', 'user_login', 'user_email', 'user_registered', 'display_name'],
        ]);

        $out = [];
        foreach ($users as $user) {
            $sessions = WP_Session_Tokens::get_instance($user->ID)->get_all();

            $out[] = [
                'id'         => (int) $user->ID,
                'login'      => $user->user_login,
                'email'      => $user->user_email,
                'name'       => $user->display_name,
                'roles'      => array_values((array) get_userdata($user->ID)->roles),
                'registered' => $user->user_registered,
                // Not the tokens themselves — a session token is a credential, and sending
                // one to Guardify would mean a breach of our database is a login to every
                // merchant's site. Only the count, and where from.
                'sessions'   => $this->describe_sessions($sessions),
            ];
        }

        return $out;
    }

    /**
     * Sessions, described without their tokens.
     */
    protected function describe_sessions(array $sessions) {
        $out = [];

        foreach ($sessions as $token => $session) {
            $out[] = [
                // A short hash so the dashboard can name one session to end without ever
                // holding the token that would let it *be* that session.
                'ref'        => substr(hash('sha256', (string) $token), 0, 16),
                'ip'         => isset($session['ip']) ? (string) $session['ip'] : '',
                'login'      => isset($session['login']) ? (int) $session['login'] : 0,
                'expiration' => isset($session['expiration']) ? (int) $session['expiration'] : 0,
                'agent'      => isset($session['ua']) ? mb_substr((string) $session['ua'], 0, 200) : '',
            ];
        }

        return $out;
    }

    // ─── The poll ────────────────────────────────────────────────────────

    /**
     * Send the inventory if it has changed, then do whatever the dashboard has queued.
     */
    public function run_poll() {
        if (!$this->is_enabled()) {
            return;
        }

        if (get_transient(self::TASK_LOCK)) {
            return;
        }
        set_transient(self::TASK_LOCK, 1, 10 * MINUTE_IN_SECONDS);

        $api = new Guardify_API();
        if (!$api->is_connected()) {
            delete_transient(self::TASK_LOCK);
            return;
        }

        $this->maybe_send_inventory($api);
        $this->run_tasks($api);

        delete_transient(self::TASK_LOCK);
    }

    /**
     * Send the inventory when it differs from what was last sent, or when it is stale.
     *
     * Compared by hash rather than resent every hour. A shop that installs nothing for a
     * month should not upload its whole plugin list seven hundred times to say so.
     */
    protected function maybe_send_inventory(Guardify_API $api) {
        $inventory = $this->collect_inventory();
        $signature = md5(wp_json_encode($inventory));

        $last      = get_option('guardify_site_inventory_hash', '');
        $last_sent = (int) get_option('guardify_site_inventory_at', 0);

        if ($signature === $last && (time() - $last_sent) < self::INVENTORY_TTL) {
            return;
        }

        $result = $api->post('/api/v1/site/inventory', $inventory);

        if (is_array($result) && empty($result['error'])) {
            update_option('guardify_site_inventory_hash', $signature, false);
            update_option('guardify_site_inventory_at', time(), false);
        }
    }

    /**
     * Take whatever the account owner queued in the dashboard and do it.
     */
    protected function run_tasks(Guardify_API $api) {
        $response = $api->get('/api/v1/site/tasks');

        if (!is_array($response) || empty($response['tasks']) || !is_array($response['tasks'])) {
            return;
        }

        $deadline = microtime(true) + self::TASK_BUDGET;

        foreach ($response['tasks'] as $task) {
            if (microtime(true) >= $deadline) {
                // The rest stay queued. An update interrupted halfway is far worse than one
                // that starts an hour later.
                break;
            }

            if (empty($task['id']) || empty($task['kind'])) {
                continue;
            }

            $result = $this->run_task((string) $task['kind'], is_array($task) ? $task : []);

            $api->post('/api/v1/site/tasks/result', [
                'task_id' => (string) $task['id'],
                'ok'      => empty($result['error']),
                'message' => isset($result['message']) ? (string) $result['message'] : '',
            ]);
        }

        // The inventory the dashboard is showing is now wrong, so send a fresh one rather
        // than leaving a merchant looking at versions that changed a moment ago.
        delete_option('guardify_site_inventory_hash');
    }

    /**
     * Perform one queued task.
     *
     * The `kind` is matched against a fixed list. There is no path here that takes a
     * caller-supplied callable, class name, file path or URL — an update installs from
     * WordPress's own update transient, which is populated by wordpress.org, not by us.
     *
     * @return array{error?:bool, message:string}
     */
    protected function run_task($kind, array $task) {
        switch ($kind) {
            case 'update_plugin':
                return $this->update_plugin(isset($task['target']) ? (string) $task['target'] : '');

            case 'update_theme':
                return $this->update_theme(isset($task['target']) ? (string) $task['target'] : '');

            case 'update_core':
                return $this->update_core();

            case 'end_sessions':
                return $this->end_sessions(
                    isset($task['user_id']) ? (int) $task['user_id'] : 0,
                    isset($task['ref']) ? (string) $task['ref'] : ''
                );

            case 'refresh_inventory':
                delete_option('guardify_site_inventory_hash');
                return ['message' => __('তালিকা হালনাগাদ হবে।', 'guardify-pro')];

            default:
                return ['error' => true, 'message' => 'unknown task'];
        }
    }

    // ─── Updates ─────────────────────────────────────────────────────────

    /**
     * Load WordPress's own upgrader machinery.
     *
     * Used rather than reimplemented. An updater that downloads and unzips by hand gets the
     * filesystem abstraction, the maintenance flag, the rollback and the permissions wrong in
     * ways that end with a broken site — and WordPress already solved all of it.
     */
    protected function load_upgrader() {
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('request_filesystem_credentials')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
    }

    /**
     * A silent upgrader skin.
     *
     * The default skin prints progress as HTML, which during a cron run goes into the
     * response body of a loopback request nobody reads — and on some hosts into the error log
     * as "headers already sent".
     */
    protected function quiet_skin() {
        return new Automatic_Upgrader_Skin();
    }

    protected function update_plugin($file) {
        if (!$this->updates_allowed()) {
            return ['error' => true, 'message' => 'updates not permitted'];
        }

        $this->load_upgrader();

        // The plugin must already be installed here and must already have an update waiting
        // in WordPress's own transient. That is what keeps this from being an install-
        // anything endpoint: the set of things it can act on is decided by wordpress.org and
        // by what the merchant already chose to run.
        if ($file === '' || !array_key_exists($file, get_plugins())) {
            return ['error' => true, 'message' => 'unknown plugin'];
        }

        wp_update_plugins();

        $updates = get_site_transient('update_plugins');
        if (empty($updates->response[$file])) {
            return ['message' => __('ইতিমধ্যে সর্বশেষ ভার্সন।', 'guardify-pro')];
        }

        // Guardify does not update Guardify from here. The upgrade would replace this file
        // while the code from it is still executing, and the merchant would be left with a
        // half-swapped plugin and no way to see what happened.
        if (strpos($file, 'guardify') === 0 || strpos($file, 'Guardify') === 0) {
            return ['error' => true, 'message' => 'guardify updates itself'];
        }

        $was_active = is_plugin_active($file);

        $upgrader = new Plugin_Upgrader($this->quiet_skin());
        $result   = $upgrader->upgrade($file);

        if (is_wp_error($result) || $result === false) {
            return [
                'error'   => true,
                'message' => is_wp_error($result) ? $result->get_error_message() : 'update failed',
            ];
        }

        // WordPress deactivates a plugin it upgrades in some paths. A shop whose payment
        // gateway comes back switched off after an overnight update is a day of lost sales.
        if ($was_active && !is_plugin_active($file)) {
            activate_plugin($file);
        }

        return ['message' => __('আপডেট সম্পন্ন।', 'guardify-pro')];
    }

    protected function update_theme($slug) {
        if (!$this->updates_allowed()) {
            return ['error' => true, 'message' => 'updates not permitted'];
        }

        $this->load_upgrader();

        if ($slug === '' || !wp_get_theme($slug)->exists()) {
            return ['error' => true, 'message' => 'unknown theme'];
        }

        wp_update_themes();

        $updates = get_site_transient('update_themes');
        if (empty($updates->response[$slug])) {
            return ['message' => __('ইতিমধ্যে সর্বশেষ ভার্সন।', 'guardify-pro')];
        }

        $upgrader = new Theme_Upgrader($this->quiet_skin());
        $result   = $upgrader->upgrade($slug);

        if (is_wp_error($result) || $result === false) {
            return [
                'error'   => true,
                'message' => is_wp_error($result) ? $result->get_error_message() : 'update failed',
            ];
        }

        return ['message' => __('থিম আপডেট সম্পন্ন।', 'guardify-pro')];
    }

    /**
     * Update WordPress itself.
     *
     * Minor releases only. A major upgrade breaks themes and plugins often enough that it is
     * not something to do to a shop while nobody is watching, and a merchant who wants one
     * can click it in their own wp-admin with the site in front of them.
     */
    protected function update_core() {
        if (!$this->updates_allowed()) {
            return ['error' => true, 'message' => 'updates not permitted'];
        }

        $this->load_upgrader();
        wp_version_check();

        $updates = get_site_transient('update_core');
        if (empty($updates->updates[0]) || $updates->updates[0]->response !== 'upgrade') {
            return ['message' => __('WordPress ইতিমধ্যে সর্বশেষ ভার্সনে আছে।', 'guardify-pro')];
        }

        $offer   = $updates->updates[0];
        $current = get_bloginfo('version');

        if (!$this->is_minor_upgrade($current, $offer->current)) {
            return [
                'error'   => true,
                'message' => 'major core upgrades are not applied remotely',
            ];
        }

        $upgrader = new Core_Upgrader($this->quiet_skin());
        $result   = $upgrader->upgrade($offer);

        if (is_wp_error($result)) {
            return ['error' => true, 'message' => $result->get_error_message()];
        }

        return ['message' => __('WordPress আপডেট সম্পন্ন।', 'guardify-pro')];
    }

    /**
     * Whether two versions differ only in their patch component.
     */
    protected function is_minor_upgrade($from, $to) {
        $a = explode('.', (string) $from);
        $b = explode('.', (string) $to);

        return isset($a[0], $a[1], $b[0], $b[1])
            && $a[0] === $b[0]
            && $a[1] === $b[1];
    }

    // ─── Sessions ────────────────────────────────────────────────────────

    /**
     * End a user's logged-in sessions.
     *
     * The reason this feature is worth having: an employee leaves, their account is removed
     * from the roster, and their browser stays logged in for a fortnight because WordPress
     * sessions outlive nothing in particular. From the dashboard a merchant can end them
     * across every shop at once.
     *
     * A specific session is named by the short hash sent with the inventory, never by its
     * token — the token is a credential, and Guardify's database does not hold one.
     */
    protected function end_sessions($user_id, $ref = '') {
        if ($user_id <= 0 || !get_userdata($user_id)) {
            return ['error' => true, 'message' => 'unknown user'];
        }

        // The last administrator cannot be locked out, whatever the queue says. A merchant
        // who ends their own only session from the dashboard has locked themselves out of
        // their own shop, and the recovery for that is a hosting support ticket.
        if ($this->is_last_administrator($user_id)) {
            return [
                'error'   => true,
                'message' => 'refusing to end the last administrator session',
            ];
        }

        $manager = WP_Session_Tokens::get_instance($user_id);

        if ($ref === '') {
            $manager->destroy_all();
            return ['message' => __('সব সেশন বন্ধ করা হয়েছে।', 'guardify-pro')];
        }

        foreach (array_keys($manager->get_all()) as $token) {
            if (hash_equals($ref, substr(hash('sha256', (string) $token), 0, 16))) {
                $manager->destroy($token);
                return ['message' => __('সেশনটি বন্ধ করা হয়েছে।', 'guardify-pro')];
            }
        }

        return ['error' => true, 'message' => 'session not found'];
    }

    /**
     * Whether this account is the only administrator that can still get in.
     */
    protected function is_last_administrator($user_id) {
        $user = get_userdata($user_id);

        if (!$user || !in_array('administrator', (array) $user->roles, true)) {
            return false;
        }

        $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 2]);

        return count($admins) <= 1;
    }

    // ─── AJAX ────────────────────────────────────────────────────────────

    /**
     * Gated on manage_options, not manage_woocommerce. Turning this on grants Guardify the
     * ability to install code on the site; that is not a decision for whoever manages orders.
     */
    protected function guard() {
        check_ajax_referer('guardify_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
    }

    public function ajax_save_settings() {
        $this->guard();

        $enabled = isset($_POST[self::OPTION_ENABLED]) && $_POST[self::OPTION_ENABLED] === 'yes' ? 'yes' : 'no';
        $updates = isset($_POST[self::OPTION_ALLOW_UPDATES]) && $_POST[self::OPTION_ALLOW_UPDATES] === 'yes' ? 'yes' : 'no';

        // Permission to install cannot be left switched on by a merchant who has switched the
        // whole feature off — the pair would then quietly come back together.
        if ($enabled === 'no') {
            $updates = 'no';
        }

        update_option(self::OPTION_ENABLED, $enabled);
        update_option(self::OPTION_ALLOW_UPDATES, $updates);

        if ($enabled === 'yes') {
            // Send the inventory promptly rather than at the next hourly tick: a merchant who
            // has just switched this on is looking at the dashboard now.
            delete_option('guardify_site_inventory_hash');
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time() + 60, 'hourly', self::CRON_HOOK);
            }
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        wp_send_json_success([
            'enabled' => $enabled === 'yes',
            'updates' => $updates === 'yes',
            'message' => $enabled === 'yes'
                ? __('সাইট ম্যানেজমেন্ট চালু হয়েছে।', 'guardify-pro')
                : __('সাইট ম্যানেজমেন্ট বন্ধ হয়েছে।', 'guardify-pro'),
        ]);
    }

    public function ajax_status() {
        $this->guard();

        $inventory = $this->is_enabled() ? $this->collect_inventory() : null;

        $outdated = 0;
        if (is_array($inventory)) {
            foreach (array_merge($inventory['plugins'], $inventory['themes']) as $item) {
                if (!empty($item['available'])) {
                    $outdated++;
                }
            }
            if (!empty($inventory['wp_available'])) {
                $outdated++;
            }
        }

        wp_send_json_success([
            'enabled'  => $this->is_enabled(),
            'updates'  => $this->updates_allowed(),
            'outdated' => $outdated,
            'admins'   => is_array($inventory) ? count($inventory['users']) : 0,
            'last_sent' => (int) get_option('guardify_site_inventory_at', 0),
        ]);
    }
}
