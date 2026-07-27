<?php
/**
 * Guardify Pro — Database backup page.
 *
 * Backups go to the Guardify engine, so the page is useless without a
 * connection and says so before showing controls that cannot work.
 */

defined('ABSPATH') || exit;

if (!current_user_can('manage_woocommerce')) {
    wp_die(esc_html__('Unauthorized', 'guardify-pro'));
}

$backup_instance = Guardify_Backup::get_instance();
$gf_can_move     = current_user_can('manage_options');
$gf_domain       = Guardify_Domain::current_domain();
$schedule_info   = $backup_instance->get_schedule_info();
$is_connected    = !empty(get_option('guardify_api_key', ''));
?>

<div class="wrap gf-wrap">

    <div class="gf-page-header">
        <div class="gf-page-header-main">
            <div class="gf-logo" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
            </div>
            <div>
                <h1 class="gf-page-title"><?php esc_html_e('ডাটাবেইজ ব্যাকআপ', 'guardify-pro'); ?></h1>
                <p class="gf-page-desc"><?php esc_html_e('আপনার WooCommerce ডাটাবেইজের অটোমেটিক ব্যাকআপ ও এক ক্লিকে রিস্টোর।', 'guardify-pro'); ?></p>
            </div>
        </div>
    </div>

    <?php if (!$is_connected) : ?>

    <div class="gf-card">
        <div class="gf-card-body">
            <div class="gf-empty-state">
                <div class="gf-empty-state-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 010 8h-1M2 8h12v9a3 3 0 01-3 3H5a3 3 0 01-3-3z"/></svg>
                </div>
                <p class="gf-empty-state-title"><?php esc_html_e('প্লাগইন সংযুক্ত নয়', 'guardify-pro'); ?></p>
                <p class="gf-empty-state-desc"><?php esc_html_e('ব্যাকআপ Guardify সার্ভারে সংরক্ষিত হয়, তাই প্রথমে API কী দিয়ে সংযুক্ত করতে হবে।', 'guardify-pro'); ?></p>
                <div class="gf-empty-state-actions">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=guardify-pro')); ?>" class="gf-btn gf-btn-primary"><?php esc_html_e('সেটিংসে যান', 'guardify-pro'); ?></a>
                </div>
            </div>
        </div>
    </div>

    <?php else : ?>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php esc_html_e('ব্যাকআপ শিডিউল', 'guardify-pro'); ?></h2>
                <p class="gf-card-desc"><?php esc_html_e('অটো ব্যাকআপ চালু থাকলে আপনাকে কিছু মনে রাখতে হবে না। রাতের দিকে সময় দিলে দোকানের ব্যস্ত সময়ে সাইট ধীর হবে না।', 'guardify-pro'); ?></p>
            </div>
            <?php if ($schedule_info['next_run']) : ?>
            <div class="gf-card-header-actions">
                <span class="gf-badge gf-badge-primary">পরবর্তী রান: <?php echo esc_html($schedule_info['next_run']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-settings-list">
                <label class="gf-toggle-row">
                    <span class="gf-toggle-info">
                        <span class="gf-toggle-label"><?php esc_html_e('অটো ব্যাকআপ চালু করুন', 'guardify-pro'); ?></span>
                        <span class="gf-toggle-desc"><?php esc_html_e('বন্ধ থাকলে ব্যাকআপ শুধু আপনি নিজে নিলে হবে — কোনো শিডিউল চলবে না।', 'guardify-pro'); ?></span>
                    </span>
                    <span class="gf-switch">
                        <input type="checkbox" id="gf-backup-enabled" <?php checked($schedule_info['enabled'], 'yes'); ?> />
                        <span class="gf-switch-slider"></span>
                    </span>
                </label>
            </div>

            <div class="gf-form-grid">
                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-frequency"><?php esc_html_e('ফ্রিকোয়েন্সি', 'guardify-pro'); ?></label>
                    <select id="gf-backup-frequency" class="gf-select">
                        <option value="every_6h" <?php selected($schedule_info['frequency'], 'every_6h'); ?>><?php esc_html_e('প্রতি ৬ ঘণ্টায়', 'guardify-pro'); ?></option>
                        <option value="every_12h" <?php selected($schedule_info['frequency'], 'every_12h'); ?>><?php esc_html_e('প্রতি ১২ ঘণ্টায়', 'guardify-pro'); ?></option>
                        <option value="daily" <?php selected($schedule_info['frequency'], 'daily'); ?>><?php esc_html_e('প্রতিদিন', 'guardify-pro'); ?></option>
                        <option value="weekly" <?php selected($schedule_info['frequency'], 'weekly'); ?>><?php esc_html_e('প্রতি সপ্তাহে', 'guardify-pro'); ?></option>
                    </select>
                    <span class="gf-help"><?php esc_html_e('দিনে অনেক অর্ডার এলে ৬ বা ১২ ঘণ্টা বেছে নিন।', 'guardify-pro'); ?></span>
                </div>

                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-time"><?php esc_html_e('সময় (২৪ ঘণ্টা ফরম্যাট)', 'guardify-pro'); ?></label>
                    <input type="time" id="gf-backup-time" class="gf-input" value="<?php echo esc_attr($schedule_info['time']); ?>" />
                    <span class="gf-help"><?php esc_html_e('দিনের কোন সময়ে ব্যাকআপ শুরু হবে।', 'guardify-pro'); ?></span>
                </div>

                <div class="gf-field">
                    <label class="gf-label" for="gf-backup-timezone"><?php esc_html_e('টাইমজোন', 'guardify-pro'); ?></label>
                    <select id="gf-backup-timezone" class="gf-select">
                        <option value="Asia/Dhaka" <?php selected($schedule_info['timezone'], 'Asia/Dhaka'); ?>><?php esc_html_e('Asia/Dhaka (বাংলাদেশ)', 'guardify-pro'); ?></option>
                        <option value="Asia/Kolkata" <?php selected($schedule_info['timezone'], 'Asia/Kolkata'); ?>><?php esc_html_e('Asia/Kolkata (ভারত)', 'guardify-pro'); ?></option>
                        <option value="UTC" <?php selected($schedule_info['timezone'], 'UTC'); ?>>UTC</option>
                    </select>
                    <span class="gf-help"><?php esc_html_e('উপরের সময়টি কোন টাইমজোনে ধরা হবে।', 'guardify-pro'); ?></span>
                </div>
            </div>
        </div>
        <div class="gf-card-footer">
            <button type="button" id="gf-save-schedule" class="gf-btn gf-btn-primary"><?php esc_html_e('শিডিউল সেভ করুন', 'guardify-pro'); ?></button>
        </div>
    </div>

    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php esc_html_e('ম্যানুয়াল ব্যাকআপ', 'guardify-pro'); ?></h2>
                <p class="gf-card-desc"><?php esc_html_e('বড় কোনো পরিবর্তনের আগে — নতুন প্লাগইন, থিম আপডেট, দাম পরিবর্তন — এখনই একটি ব্যাকআপ নিয়ে রাখুন।', 'guardify-pro'); ?></p>
            </div>
        </div>
        <div class="gf-card-body">
            <div class="gf-row">
                <button type="button" id="gf-backup-now" class="gf-btn gf-btn-secondary"><?php esc_html_e('এখনই ব্যাকআপ নিন', 'guardify-pro'); ?></button>
                <span id="gf-backup-status" class="gf-help"></span>
            </div>
        </div>
    </div>

    <?php if ($gf_can_move) : ?>
    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php esc_html_e('ছবি ও মিডিয়া ব্যাকআপ', 'guardify-pro'); ?></h2>
                <p class="gf-card-desc">
                    <?php
                    printf(
                        /* translators: %s is the folder name wp-content/uploads */
                        esc_html__('ডাটাবেইজ ব্যাকআপে আপনার অর্ডার ও পণ্যের তথ্য থাকে, কিন্তু ছবিগুলো থাকে না। এটি চালু করলে %s ফোল্ডারের ছবিও Guardify-তে জমা থাকবে।', 'guardify-pro'),
                        '<code>wp-content/uploads</code>'
                    );
                    ?>
                </p>
            </div>
            <div class="gf-card-header-actions">
                <span id="gf-media-usage" class="gf-badge gf-badge-muted"></span>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                    <strong class="gf-alert-title"><?php esc_html_e('আপনার সার্ভারে চাপ পড়বে না', 'guardify-pro'); ?></strong>
                    <?php esc_html_e('আপনার সাইট শুধু ফাইলের নাম, আকার ও তারিখ পড়ে পাঠায় — কোনো ফাইল স্ক্যান বা হিসাব করে না। কোন ছবিটি নতুন সেটা Guardify ঠিক করে, আর যেগুলো আগেই জমা আছে সেগুলো আবার আপলোড হয় না। কাজটি ছোট ছোট ভাগে পটভূমিতে চলে।', 'guardify-pro'); ?>
                </div>
            </div>

            <div class="gf-settings-list">
                <label class="gf-toggle-row">
                    <span class="gf-toggle-info">
                        <span class="gf-toggle-label"><?php esc_html_e('মিডিয়া ব্যাকআপ চালু করুন', 'guardify-pro'); ?></span>
                        <span class="gf-toggle-desc"><?php esc_html_e('প্রথমবার সব ছবি আপলোড হতে সময় লাগবে। এরপর শুধু নতুন ও পরিবর্তিত ছবি যাবে।', 'guardify-pro'); ?></span>
                    </span>
                    <span class="gf-switch">
                        <input type="checkbox" id="gf-media-enabled" <?php checked(get_option('guardify_media_enabled', 'no'), 'yes'); ?> />
                        <span class="gf-switch-slider"></span>
                    </span>
                </label>
            </div>

            <div id="gf-media-live" class="gf-hidden"></div>
            <div id="gf-media-status"></div>

            <div class="gf-row">
                <button type="button" id="gf-media-sync" class="gf-btn gf-btn-secondary"><?php esc_html_e('এখনই মিডিয়া ব্যাকআপ নিন', 'guardify-pro'); ?></button>
                <button type="button" id="gf-media-restore" class="gf-btn gf-btn-ghost"><?php esc_html_e('মিডিয়া রিস্টোর', 'guardify-pro'); ?></button>
                <button type="button" id="gf-media-cancel" class="gf-btn gf-btn-ghost gf-hidden"><?php esc_html_e('বাতিল করুন', 'guardify-pro'); ?></button>
            </div>

            <p class="gf-help">
                <?php
                printf(
                    /* translators: %s is the name of the "media restore" button, emphasised */
                    esc_html__('%s Guardify-তে জমা ছবিগুলো আবার সাইটে নামায়। এটি কোনো ফাইল মুছে না — যেগুলো ঠিক আছে সেগুলোতে হাত দেয় না, তাই সাইট চালু থাকা অবস্থায়ও নিরাপদে চালানো যায়। সাইট নতুন সার্ভারে নেওয়ার পর এটিই ছবিগুলো ফিরিয়ে আনে।', 'guardify-pro'),
                    '<strong>' . esc_html__('মিডিয়া রিস্টোর', 'guardify-pro') . '</strong>'
                );
                ?>
            </p>

            <div id="gf-media-skips"></div>
        </div>
    </div>
    <?php endif; ?>

    <div class="gf-card gf-card-danger">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php esc_html_e('রিস্টোর', 'guardify-pro'); ?></h2>
                <p class="gf-card-desc">
                    <?php esc_html_e('আগের কোনো ব্যাকআপ থেকে ডাটাবেইজ ফিরিয়ে আনুন। রিস্টোর শুরু করতে হয় Guardify ড্যাশবোর্ড থেকে — সেখানে আমরা ফাইলটি পুরোপুরি যাচাই করে একটি কোড দিই।', 'guardify-pro'); ?>
                </p>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                    <strong class="gf-alert-title"><?php esc_html_e('আপনার সাইট চালু থাকবে', 'guardify-pro'); ?></strong>
                    <?php esc_html_e('Guardify নতুন ডাটা আলাদা টেবিলে তৈরি করে, তারপর এক ধাপে বদলে দেয়। মাঝপথে কিছু ভুল হলে আপনার বর্তমান ডাটাবেইজে কোনো পরিবর্তন হবে না।', 'guardify-pro'); ?>
                </div>
            </div>

            <div class="gf-alert gf-alert-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
                <div>
                    <strong class="gf-alert-title"><?php esc_html_e('ব্যাকআপের পরের অর্ডারগুলো থাকবে না', 'guardify-pro'); ?></strong>
                    <?php esc_html_e('যে সময়ের ব্যাকআপ ফেরাচ্ছেন, তার পরে আসা অর্ডার ও পরিবর্তন মুছে যাবে।', 'guardify-pro'); ?>
                </div>
            </div>

            <div id="gf-restore-live" class="gf-hidden"></div>

            <div class="gf-field gf-field-wide">
                <label class="gf-label" for="gf-restore-token"><?php esc_html_e('রিস্টোর কোড', 'guardify-pro'); ?></label>
                <input type="text" id="gf-restore-token" class="gf-input gf-input-mono"
                       autocomplete="off" spellcheck="false" placeholder="<?php echo esc_attr__('Guardify ড্যাশবোর্ড থেকে পাওয়া কোড', 'guardify-pro'); ?>">
                <span class="gf-help">
                    <?php
                    printf(
                        /* translators: %s is a link to the Guardify dashboard */
                        esc_html__('%s — ব্যাকআপ বেছে নিয়ে সাইটের ডোমেইন লিখে নিশ্চিত করলে কোডটি পাবেন। কোড ৩০ মিনিট পর্যন্ত বৈধ।', 'guardify-pro'),
                        '<a href="https://guardify.pro/backups" target="_blank" rel="noopener">'
                            . esc_html__('ড্যাশবোর্ডে যান', 'guardify-pro') . '</a>'
                    );
                    ?>
                </span>
            </div>

            <div id="gf-restore-status"></div>

            <div class="gf-row">
                <button type="button" id="gf-restore-btn" class="gf-btn gf-btn-danger"><?php esc_html_e('রিস্টোর শুরু করুন', 'guardify-pro'); ?></button>
                <button type="button" id="gf-restore-abort" class="gf-btn gf-btn-ghost gf-hidden"><?php esc_html_e('বাতিল করুন', 'guardify-pro'); ?></button>
            </div>

            <div id="gf-backup-list-container">
                <div class="gf-loading">
                    <span class="gf-spinner" aria-hidden="true"></span>
                    <?php esc_html_e('ব্যাকআপ লোড হচ্ছে…', 'guardify-pro'); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($gf_can_move) : ?>
    <div class="gf-card">
        <div class="gf-card-header">
            <div class="gf-card-heading">
                <h2 class="gf-card-title"><?php esc_html_e('ডোমেইন পরিবর্তন', 'guardify-pro'); ?></h2>
                <p class="gf-card-desc">
                    <?php esc_html_e('সাইট নতুন ডোমেইনে নিয়ে যাচ্ছেন? এক ক্লিকে সব ঠিকানা বদলে যাবে — পোস্ট, পণ্যের ছবি, থিম ও প্লাগইনের সেটিংস, সবকিছু।', 'guardify-pro'); ?>
                </p>
            </div>
            <div class="gf-card-header-actions">
                <span class="gf-badge gf-badge-muted gf-mono"><?php echo esc_html($gf_domain); ?></span>
            </div>
        </div>
        <div class="gf-card-body gf-stack">
            <div class="gf-alert gf-alert-info">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                    <strong class="gf-alert-title"><?php esc_html_e('সাধারণ সার্চ-রিপ্লেস প্লাগইনের মতো নয়', 'guardify-pro'); ?></strong>
                    <?php esc_html_e('WordPress অনেক সেটিংস এমন ফরম্যাটে রাখে যেখানে লেখার দৈর্ঘ্যও সংরক্ষিত থাকে। সাধারণ রিপ্লেস করলে ওই হিসাব ভেঙে যায় আর উইজেট, থিম ও পেমেন্ট সেটিংস চুপচাপ মুছে যায় — কোনো এরর ছাড়াই। Guardify কাজটি নিজের সার্ভারে করে, সঠিক হিসাব রেখে।', 'guardify-pro'); ?>
                </div>
            </div>

            <div id="gf-domain-live" class="gf-hidden"></div>

            <div class="gf-field gf-field-wide" id="gf-domain-input-wrap">
                <label class="gf-label" for="gf-domain-new"><?php esc_html_e('নতুন ডোমেইন', 'guardify-pro'); ?></label>
                <input type="text" id="gf-domain-new" class="gf-input gf-input-mono"
                       autocomplete="off" spellcheck="false" placeholder="newshop.com.bd">
                <span class="gf-help">
                    <?php esc_html_e('শুধু ঠিকানাটি লিখুন — https:// বা www. লেখার দরকার নেই। শুরু করার আগে নিশ্চিত করুন নতুন ডোমেইনটি এই সাইটের দিকেই পয়েন্ট করা আছে।', 'guardify-pro'); ?>
                </span>
            </div>

            <div class="gf-alert gf-alert-warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9L2.4 17.5c-.8.9.2 2.5 1.7 2.5h15.8c1.5 0 2.5-1.6 1.7-2.5L13.7 3.9c-.8-.8-2.7-.8-3.4 0z"/></svg>
                <div>
                    <strong class="gf-alert-title"><?php esc_html_e('শেষ হলে আপনাকে নতুন ঠিকানায় লগইন করতে হবে', 'guardify-pro'); ?></strong>
                    <?php esc_html_e('পরিবর্তনের সময় সাইট চালু থাকবে। শেষ ধাপে ঠিকানা বদলে যাবে, তাই তখন পুরোনো ঠিকানায় wp-admin আর খুলবে না।', 'guardify-pro'); ?>
                </div>
            </div>

            <div id="gf-domain-status"></div>

            <div class="gf-row">
                <button type="button" id="gf-domain-start" class="gf-btn gf-btn-primary"><?php esc_html_e('ডোমেইন পরিবর্তন করুন', 'guardify-pro'); ?></button>
                <button type="button" id="gf-domain-cancel" class="gf-btn gf-btn-ghost gf-hidden"><?php esc_html_e('বাতিল করুন', 'guardify-pro'); ?></button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
</div>

<script>
/* Every Bengali string on this page, translated by PHP and handed to the
   script as one object. Inline JavaScript cannot call __() itself, and opening
   a PHP tag inside a JS string literal produces something neither PHP nor the
   browser can parse — so the strings are lifted out rather than wrapped where
   they sit. wp_json_encode does the escaping, once. */
var GF_I18N = <?php echo wp_json_encode([
        's1aa80bab' => __(' ঠিকানায় চলবে।', 'guardify-pro'),
        's1d3a00d2' => __('কোনো ফাইল মুছে ফেলা হবে না — যেগুলো ইতিমধ্যে ঠিক আছে সেগুলোতে হাত দেওয়া হবে না।', 'guardify-pro'),
        's20f15402' => __('ডোমেইন পরিবর্তন ব্যর্থ হয়েছে।', 'guardify-pro'),
        's2880bb05' => __('আপনি কি নিশ্চিত?

এই ব্যাকআপের পরে আসা সব অর্ডার ও পরিবর্তন মুছে যাবে। নতুন ডাটা প্রস্তুত না হওয়া পর্যন্ত আপনার সাইট স্বাভাবিকভাবে চলবে।', 'guardify-pro'),
        's2900b4cd' => __('ব্যাকআপ লোড করা যায়নি।', 'guardify-pro'),
        's402f5858' => __('ব্যাকআপ ব্যর্থ হয়েছে।', 'guardify-pro'),
        's43bf0afd' => __('টি নামানো হয়েছে, ', 'guardify-pro'),
        's4ab577e9' => __('টি ফাইল', 'guardify-pro'),
        's4eb5bd5b' => __('সংযোগ বিচ্ছিন্ন হয়েছে — সম্ভবত সাইটটি নতুন ঠিকানায় চলে গেছে। নতুন ঠিকানায় গিয়ে দেখুন।', 'guardify-pro'),
        's4f8f4fca' => __('ডোমেইন পরিবর্তন বাতিল করবেন? আপনার সাইটে কোনো পরিবর্তন হয়নি।', 'guardify-pro'),
        's53727483' => __('নতুন ডোমেইনটি লিখুন।', 'guardify-pro'),
        's58f493b2' => __('টি আগেই ঠিক আছে।', 'guardify-pro'),
        's599ec958' => __('সার্ভারের সাথে যোগাযোগ ব্যর্থ হয়েছে।', 'guardify-pro'),
        's5acba387' => __('রিস্টোর শুরু করা যায়নি।', 'guardify-pro'),
        's6cfa66f6' => __('ডোমেইন পরিবর্তন সম্পন্ন হয়েছে। এখন থেকে সাইটটি ', 'guardify-pro'),
        's71b0d613' => __('সেভ করা যায়নি।', 'guardify-pro'),
        's742bfd23' => __('শুরু করা যায়নি।', 'guardify-pro'),
        's7c188d46' => __('১,২৪০টি ফাইল', 'guardify-pro'),
        's7d0f8c60' => __('কোডটি সঠিক নয়। Guardify ড্যাশবোর্ড থেকে কোডটি কপি করে বসান।', 'guardify-pro'),
        's7f9559b5' => __('ব্যাকআপ শুরু হয়েছে।', 'guardify-pro'),
        's83d9157f' => __(' এ বদলে যাবে। ', 'guardify-pro'),
        's93e44ce6' => __('মোট ', 'guardify-pro'),
        'sa04a9e57' => __('বাতিল হয়েছে।', 'guardify-pro'),
        'sa30ee22f' => __('শিডিউল সেভ করা যায়নি।', 'guardify-pro'),
        'sa5a4c3d9' => __('পরিবর্তনের সময় সাইট চালু থাকবে, তবে শেষ হলে আপনাকে নতুন ঠিকানায় লগইন করতে হবে।', 'guardify-pro'),
        'sa5e466c0' => __('টি দেখানো হচ্ছে)', 'guardify-pro'),
        'sa8a4af8f' => __('টি আপলোড হয়েছে (', 'guardify-pro'),
        'saa963b21' => __('টি ফাইল দেখা হয়েছে।', 'guardify-pro'),
        'sae82c296' => __('আপনি কি নিশ্চিত?

সাইটের সব ঠিকানা ', 'guardify-pro'),
        'sbe97a263' => __('ব্যাকআপ সম্পন্ন হয়েছে।', 'guardify-pro'),
        'sc6660d40' => __('কিছু ফাইল বাদ পড়েছে (', 'guardify-pro'),
        'scc4234d9' => __('চলমান মিডিয়া কাজটি বাতিল করবেন? যা আপলোড হয়েছে তা জমা থাকবে।', 'guardify-pro'),
        'scddd1823' => __('শিডিউল সেভ হয়েছে।', 'guardify-pro'),
        'scfaeac3f' => __('Guardify-তে জমা ছবিগুলো এই সাইটে নামানো হবে।

', 'guardify-pro'),
        'sd5bad13b' => __('ব্যাকআপ চলছে… ', 'guardify-pro'),
        'sded0d5f4' => __('রিস্টোর বাতিল করবেন? আপনার সাইটে কোনো পরিবর্তন হয়নি।', 'guardify-pro'),
        'se633db51' => __('আগে মিডিয়া ব্যাকআপ চালু করুন।', 'guardify-pro'),
        'sf2a30502' => __('কোনো ব্যাকআপ নেই। উপরের বাটনে ক্লিক করে প্রথম ব্যাকআপ নিন।', 'guardify-pro'),
        'sf4b5d46d' => __('সংরক্ষিত ব্যাকআপ', 'guardify-pro'),
        'sfc3c1d8d' => __(' টি ব্যাকআপ আছে। কোনটি ফেরাবেন তা Guardify ড্যাশবোর্ড থেকে বেছে নিন।', 'guardify-pro'),
    ]); ?>;

jQuery(function ($) {
    var ajaxUrl = guardifyData.ajaxUrl;
    var nonce   = guardifyData.nonce;
    var GF      = window.Guardify;

    /* ── Backup list ──────────────────────────────────────────────────── */

    function loadBackups() {
        $.post(ajaxUrl, { action: 'guardify_backup_list', _ajax_nonce: nonce }, function (res) {
            var $container = $('#gf-backup-list-container').empty();

            if (!res.success || !res.data) {
                $container.append($('<p>').addClass('gf-help').text(GF_I18N.s2900b4cd));
                return;
            }

            var backups = res.data.backups || [];
            var count   = res.data.count || 0;

            if (!backups.length) {
                $container.append(
                    $('<p>').addClass('gf-help').text(GF_I18N.sf2a30502)
                );
                return;
            }

            var $list = $('<ul>').addClass('gf-kv');
            backups.slice(0, 10).forEach(function (b) {
                var d     = new Date(b.created_at);
                var label = d.toLocaleDateString('bn-BD', {
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit'
                });
                var size  = (b.file_size / 1024 / 1024).toFixed(2) + ' MB';
                // Built with the DOM API rather than string concatenation: a backup note is
                // free text a merchant typed, and one containing markup would otherwise be
                // rendered as markup.
                $list.append($('<li>').append(
                    $('<span>').text(label),
                    $('<span>').addClass('gf-text-muted').text(size + (b.note ? ' — ' + b.note : ''))
                ));
            });

            $container.append(
                $('<p>').addClass('gf-label').text(GF_I18N.sf4b5d46d),
                $list,
                $('<p>').addClass('gf-help').text(
                    GF_I18N.s93e44ce6 + count + GF_I18N.sfc3c1d8d
                )
            );
        });
    }

    loadBackups();

    /* ── Manual backup ────────────────────────────────────────────────── */

    /*
     * The dump runs in the background in bounded slices, so this button only queues it.
     * Holding an admin-ajax request open for the length of a database dump would hit the
     * PHP timeout on any large shop and report a failure for a backup that was still
     * running perfectly well — so progress is polled instead.
     */
    var pollTimer = null;

    function pollBackup($btn, $status) {
        $.post(ajaxUrl, { action: 'guardify_backup_status', _ajax_nonce: nonce }, function (res) {
            if (!res.success) { return; }

            if (res.data.running) {
                var pct = res.data.percent || 0;
                $status.removeClass('gf-success gf-error')
                    .text(GF_I18N.sd5bad13b + pct + '%');
                pollTimer = setTimeout(function () { pollBackup($btn, $status); }, 3000);
                return;
            }

            GF.setLoading($btn, false);

            var last = res.data.last;
            if (last && last.ok) {
                $status.addClass('gf-success').text(last.message || GF_I18N.sbe97a263);
                GF.toast(last.message || GF_I18N.sbe97a263, { type: 'success' });
                loadBackups();
            } else if (last) {
                $status.addClass('gf-error').text(last.message || GF_I18N.s402f5858);
            } else {
                $status.text('');
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            $status.addClass('gf-error').text(GF_I18N.s599ec958);
        });
    }

    $('#gf-backup-now').on('click', function () {
        var $btn = $(this);
        var $status = $('#gf-backup-status').removeClass('gf-success gf-error').text('');

        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        GF.setLoading($btn, true);

        $.post(ajaxUrl, { action: 'guardify_backup_now', _ajax_nonce: nonce }, function (res) {
            if (res.success) {
                $status.text(res.data.message || GF_I18N.s7f9559b5);
                pollTimer = setTimeout(function () { pollBackup($btn, $status); }, 2000);
            } else {
                GF.setLoading($btn, false);
                $status.addClass('gf-error').text(res.data || GF_I18N.s402f5858);
            }
        }).fail(function () {
            GF.setLoading($btn, false);
            $status.addClass('gf-error').text(GF_I18N.s599ec958);
        });
    });

    // A dump queued in an earlier page view is still running; pick its progress back up
    // rather than showing an idle screen while the site is mid-backup.
    (function resumeIfRunning() {
        $.post(ajaxUrl, { action: 'guardify_backup_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.running) {
                var $btn = $('#gf-backup-now');
                GF.setLoading($btn, true);
                pollBackup($btn, $('#gf-backup-status'));
            }
        });
    })();

    /* ── Restore ──────────────────────────────────────────────────────── */

    /*
     * The plugin never decides that a restore may happen — it only carries one out. The
     * archive is verified end to end on Guardify's side and the target domain is confirmed
     * there, which is both a safety property and the reason a 400MB integrity check does
     * not run on the merchant's hosting.
     */
    var restoreTimer = null;

    function restoreNotice(type, text) {
        $('#gf-restore-status').html($('<div>').addClass('gf-alert gf-alert-' + type).text(text));
    }

    function pollRestore() {
        $.post(ajaxUrl, { action: 'guardify_restore_status', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { return; }

                if (res.data.running) {
                    var line = res.data.message;
                    if (res.data.stage === 'download' && res.data.expected > 0) {
                        line += ' ' + Math.round((res.data.downloaded / res.data.expected) * 100) + '%';
                    }
                    $('#gf-restore-live').removeClass('gf-hidden')
                        .html($('<div>').addClass('gf-alert gf-alert-info').text(line));
                    $('#gf-restore-abort').removeClass('gf-hidden');
                    restoreTimer = setTimeout(pollRestore, 4000);
                    return;
                }

                $('#gf-restore-live').addClass('gf-hidden').empty();
                $('#gf-restore-abort').addClass('gf-hidden');
                GF.setLoading($('#gf-restore-btn'), false);

                var last = res.data.last;
                if (last && last.ok) {
                    restoreNotice('success', last.message);
                    GF.toast(last.message, { type: 'success' });
                    loadBackups();
                } else if (last) {
                    restoreNotice('error', last.message);
                }
            })
            .fail(function () {
                GF.setLoading($('#gf-restore-btn'), false);
                restoreNotice('error', GF_I18N.s599ec958);
            });
    }

    $('#gf-restore-btn').on('click', function () {
        var $btn  = $(this);
        var token = $.trim($('#gf-restore-token').val());

        if (!/^[a-f0-9]{64}$/i.test(token)) {
            restoreNotice('error', GF_I18N.s7d0f8c60);
            return;
        }

        if (!confirm(GF_I18N.s2880bb05)) {
            return;
        }

        if (restoreTimer) { clearTimeout(restoreTimer); restoreTimer = null; }
        $('#gf-restore-status').empty();
        GF.setLoading($btn, true);

        $.post(ajaxUrl, { action: 'guardify_restore_start', token: token, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    GF.setLoading($btn, false);
                    restoreNotice('error', res.data || GF_I18N.s5acba387);
                    return;
                }
                $('#gf-restore-token').val('');
                restoreNotice('info', res.data.message);
                restoreTimer = setTimeout(pollRestore, 2000);
            })
            .fail(function () {
                GF.setLoading($btn, false);
                restoreNotice('error', GF_I18N.s599ec958);
            });
    });

    $('#gf-restore-abort').on('click', function () {
        if (!confirm(GF_I18N.sded0d5f4)) { return; }
        $.post(ajaxUrl, { action: 'guardify_restore_abort', _ajax_nonce: nonce })
            .always(function () {
                if (restoreTimer) { clearTimeout(restoreTimer); restoreTimer = null; }
                pollRestore();
            });
    });

    // A restore queued in an earlier page view is still running; pick it back up rather
    // than showing an idle screen while the database is mid-swap.
    (function resumeRestore() {
        $.post(ajaxUrl, { action: 'guardify_restore_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.running) {
                GF.setLoading($('#gf-restore-btn'), true);
                pollRestore();
            }
        });
    })();

    /* ── Domain change ────────────────────────────────────────────────── */

    /*
     * One click, three stages the merchant never has to think about: a fresh backup, the
     * engine rewriting every URL in it, and a restore that swaps the result in atomically.
     * Polling drives it because each stage is already a sliced background job — this only
     * asks whether the current one has finished.
     */
    var domainTimer = null;

    function domainNotice(type, text) {
        $('#gf-domain-status').html($('<div>').addClass('gf-alert gf-alert-' + type).text(text));
    }

    function domainRunning(running) {
        $('#gf-domain-input-wrap').toggleClass('gf-hidden', running);
        $('#gf-domain-cancel').toggleClass('gf-hidden', !running);
        GF.setLoading($('#gf-domain-start'), running);
    }

    function pollDomain() {
        $.post(ajaxUrl, { action: 'guardify_domain_advance', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    domainRunning(false);
                    domainNotice('error', res.data || GF_I18N.s20f15402);
                    return;
                }

                var d = res.data;

                if (d.error) {
                    domainRunning(false);
                    $('#gf-domain-live').addClass('gf-hidden').empty();
                    domainNotice('error', d.error);
                    return;
                }

                if (d.done) {
                    domainRunning(false);
                    $('#gf-domain-live').addClass('gf-hidden').empty();
                    domainNotice('success',
                        GF_I18N.s6cfa66f6 + d.new + GF_I18N.s1aa80bab);
                    // The old address no longer serves wp-admin, so staying here would show
                    // a screen whose every subsequent request fails. Sent on rather than
                    // left to discover it.
                    setTimeout(function () {
                        window.location.href = 'https://' + d.new + '/wp-admin/admin.php?page=guardify-backup';
                    }, 2500);
                    return;
                }

                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(d.message));
                domainTimer = setTimeout(pollDomain, 5000);
            })
            .fail(function () {
                // A failure here is usually the site having just moved: the browser is
                // still on the old address and admin-ajax is answering from the new one.
                domainRunning(false);
                domainNotice('warning',
                    GF_I18N.s4eb5bd5b);
            });
    }

    $('#gf-domain-start').on('click', function () {
        var newDomain = $.trim($('#gf-domain-new').val());

        if (!newDomain) {
            domainNotice('error', GF_I18N.s53727483);
            return;
        }

        if (!confirm(GF_I18N.sae82c296 + newDomain + GF_I18N.s83d9157f +
                     GF_I18N.sa5a4c3d9)) {
            return;
        }

        if (domainTimer) { clearTimeout(domainTimer); domainTimer = null; }
        $('#gf-domain-status').empty();
        domainRunning(true);

        $.post(ajaxUrl, { action: 'guardify_domain_start', new_domain: newDomain, _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    domainRunning(false);
                    domainNotice('error', res.data || GF_I18N.s742bfd23);
                    return;
                }
                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                domainTimer = setTimeout(pollDomain, 4000);
            })
            .fail(function () {
                domainRunning(false);
                domainNotice('error', GF_I18N.s599ec958);
            });
    });

    $('#gf-domain-cancel').on('click', function () {
        if (!confirm(GF_I18N.s4f8f4fca)) { return; }

        if (domainTimer) { clearTimeout(domainTimer); domainTimer = null; }

        $.post(ajaxUrl, { action: 'guardify_domain_cancel', _ajax_nonce: nonce })
            .done(function (res) {
                domainRunning(false);
                $('#gf-domain-live').addClass('gf-hidden').empty();
                domainNotice('info', (res.data && res.data.message) || GF_I18N.sa04a9e57);
            });
    });

    // A change started before this page was loaded — a closed browser, a reload — is picked
    // back up rather than appearing not to be happening.
    if ($('#gf-domain-start').length) {
        $.post(ajaxUrl, { action: 'guardify_domain_status', _ajax_nonce: nonce }, function (res) {
            if (res.success && res.data.stage && res.data.stage !== 'failed') {
                domainRunning(true);
                $('#gf-domain-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                domainTimer = setTimeout(pollDomain, 3000);
            } else if (res.success && res.data.error) {
                domainNotice('error', res.data.error);
            }
        });
    }


    /* ── Media ────────────────────────────────────────────────────────── */

    var mediaTimer = null;

    function mediaNotice(type, message) {
        $('#gf-media-status').html(
            $('<div>').addClass('gf-alert gf-alert-' + type).text(message)
        );
    }

    function mediaRunning(running) {
        $('#gf-media-sync, #gf-media-restore').prop('disabled', running);
        $('#gf-media-cancel').toggleClass('gf-hidden', !running);
    }

    // Sizes are shown in Bengali numerals to match the rest of the product. A merchant reading
    // "৪.২ GB" beside GF_I18N.s7c188d46 should not find one of the two in Latin digits.
    function bnDigits(text) {
        var map = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
        return String(text).replace(/[0-9]/g, function (d) { return map[+d]; });
    }

    function humanBytes(bytes) {
        bytes = +bytes || 0;
        if (bytes < 1024) { return bnDigits(bytes) + ' B'; }
        var units = ['KB', 'MB', 'GB', 'TB'];
        var value = bytes / 1024;
        var i = 0;
        while (value >= 1024 && i < units.length - 1) { value /= 1024; i++; }
        return bnDigits(value.toFixed(value < 10 ? 1 : 0)) + ' ' + units[i];
    }

    function renderUsage(usage) {
        var $badge = $('#gf-media-usage');

        if (!usage || !usage.enabled) {
            // A free-plan site gets no ceiling and no number. Showing "০ B / ০ B" would read
            // as a broken feature rather than as one this plan does not include.
            $badge.text('').addClass('gf-hidden');
            return;
        }

        $badge.removeClass('gf-hidden').text(
            humanBytes(usage.bytes) + ' / ' + humanBytes(usage.ceiling) +
            '  ·  ' + bnDigits(usage.files) + GF_I18N.s4ab577e9
        );
    }

    function renderSkips(skips) {
        var $box = $('#gf-media-skips').empty();
        if (!skips || !skips.length) { return; }

        // Shown rather than logged. A media backup with holes in it is only useful if the
        // merchant can find out which files are missing and why, and the two commonest
        // reasons — a blocked file type and a file over the size limit — are both things they
        // can look at and understand.
        var $alert = $('<div>').addClass('gf-alert gf-alert-warning');
        $alert.append($('<strong>').addClass('gf-alert-title')
            .text(GF_I18N.sc6660d40 + bnDigits(skips.length) + GF_I18N.sa5e466c0));

        var $list = $('<ul>').addClass('gf-alert-list');
        skips.forEach(function (s) {
            $list.append($('<li>').append(
                $('<code>').text(s.path),
                document.createTextNode(' — ' + (s.reason || ''))
            ));
        });

        $box.append($alert.append($list));
    }

    function pollMedia() {
        $.post(ajaxUrl, { action: 'guardify_media_status', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) { mediaRunning(false); return; }

                var d = res.data;

                if (d.state === 'syncing') {
                    mediaRunning(true);
                    $('#gf-media-live').removeClass('gf-hidden').html(
                        $('<div>').addClass('gf-alert gf-alert-info').text(
                            d.message + '  ' +
                            bnDigits(d.uploaded) + GF_I18N.sa8a4af8f + humanBytes(d.bytes) + '), ' +
                            bnDigits(d.seen) + GF_I18N.saa963b21
                        )
                    );
                    mediaTimer = setTimeout(pollMedia, 5000);
                    return;
                }

                if (d.state === 'restoring') {
                    mediaRunning(true);
                    $('#gf-media-live').removeClass('gf-hidden').html(
                        $('<div>').addClass('gf-alert gf-alert-info').text(
                            d.message + '  ' + bnDigits(d.written) + GF_I18N.s43bf0afd +
                            bnDigits(d.present) + GF_I18N.s58f493b2
                        )
                    );
                    mediaTimer = setTimeout(pollMedia, 5000);
                    return;
                }

                mediaRunning(false);
                $('#gf-media-live').addClass('gf-hidden').empty();
                renderUsage(d.usage);

                if (d.last) {
                    mediaNotice(d.last.ok ? 'success' : 'error', d.last.message);
                }
                renderSkips(d.skips);
            })
            .fail(function () {
                mediaRunning(false);
            });
    }

    $('#gf-media-enabled').on('change', function () {
        var enabled = $(this).is(':checked') ? 'yes' : 'no';

        $.post(ajaxUrl, {
            action: 'guardify_media_save_settings',
            guardify_media_enabled: enabled,
            _ajax_nonce: nonce
        }, function (res) {
            if (res.success) {
                GF.toast(res.data.message, { type: 'success' });
            } else {
                GF.toast(res.data || GF_I18N.s71b0d613, { type: 'error' });
            }
        });
    });

    $('#gf-media-sync').on('click', function () {
        if (!$('#gf-media-enabled').is(':checked')) {
            mediaNotice('warning', GF_I18N.se633db51);
            return;
        }

        if (mediaTimer) { clearTimeout(mediaTimer); mediaTimer = null; }
        $('#gf-media-status').empty();
        $('#gf-media-skips').empty();
        mediaRunning(true);

        $.post(ajaxUrl, { action: 'guardify_media_start', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    mediaRunning(false);
                    mediaNotice('error', res.data || GF_I18N.s742bfd23);
                    return;
                }
                $('#gf-media-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                mediaTimer = setTimeout(pollMedia, 4000);
            })
            .fail(function () {
                mediaRunning(false);
                mediaNotice('error', GF_I18N.s599ec958);
            });
    });

    $('#gf-media-restore').on('click', function () {
        if (!confirm(GF_I18N.scfaeac3f +
                     GF_I18N.s1d3a00d2)) {
            return;
        }

        if (mediaTimer) { clearTimeout(mediaTimer); mediaTimer = null; }
        $('#gf-media-status').empty();
        mediaRunning(true);

        $.post(ajaxUrl, { action: 'guardify_media_restore', _ajax_nonce: nonce })
            .done(function (res) {
                if (!res.success) {
                    mediaRunning(false);
                    mediaNotice('error', res.data || GF_I18N.s5acba387);
                    return;
                }
                $('#gf-media-live').removeClass('gf-hidden')
                    .html($('<div>').addClass('gf-alert gf-alert-info').text(res.data.message));
                mediaTimer = setTimeout(pollMedia, 4000);
            })
            .fail(function () {
                mediaRunning(false);
                mediaNotice('error', GF_I18N.s599ec958);
            });
    });

    $('#gf-media-cancel').on('click', function () {
        if (!confirm(GF_I18N.scc4234d9)) { return; }

        if (mediaTimer) { clearTimeout(mediaTimer); mediaTimer = null; }

        $.post(ajaxUrl, { action: 'guardify_media_cancel', _ajax_nonce: nonce })
            .done(function (res) {
                mediaRunning(false);
                $('#gf-media-live').addClass('gf-hidden').empty();
                mediaNotice('info', (res.data && res.data.message) || GF_I18N.sa04a9e57);
            });
    });

    // A sync started before this page was loaded is picked back up, rather than the screen
    // showing nothing while the merchant's server is busy uploading.
    if ($('#gf-media-sync').length) {
        pollMedia();
    }


    /* ── Schedule ─────────────────────────────────────────────────────── */

    $('#gf-save-schedule').on('click', function () {
        var $btn = $(this);
        GF.setLoading($btn, true);

        $.post(ajaxUrl, {
            action: 'guardify_backup_save_schedule',
            _ajax_nonce: nonce,
            guardify_backup_enabled: $('#gf-backup-enabled').is(':checked') ? 'yes' : 'no',
            guardify_backup_frequency: $('#gf-backup-frequency').val(),
            guardify_backup_time: $('#gf-backup-time').val(),
            guardify_backup_timezone: $('#gf-backup-timezone').val()
        }, function (res) {
            if (res.success) {
                GF.toast(res.data.message || GF_I18N.scddd1823, { type: 'success' });
            } else {
                GF.toast(res.data || GF_I18N.sa30ee22f, { type: 'error' });
            }
        }).fail(function () {
            GF.toast(GF_I18N.s599ec958, { type: 'error' });
        }).always(function () {
            GF.setLoading($btn, false);
        });
    });
});
</script>
