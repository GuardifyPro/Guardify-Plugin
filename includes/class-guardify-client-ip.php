<?php
defined('ABSPATH') || exit;

/**
 * Guardify Client IP — one place that decides what a visitor's address is.
 *
 * There were three copies of this logic, and all three trusted whatever the request said
 * about itself: they walked CF-Connecting-IP, X-Real-IP and X-Forwarded-For in turn and
 * returned the first thing that parsed as a public address.
 *
 * Those headers are just request headers. Anyone can send them. In a plugin whose whole job
 * is refusing fraudulent orders, that meant two things at once:
 *
 *   1. Every IP block was bypassable by sending one header. A blocked customer types a random
 *      address into their browser's developer tools and the shop lets them straight back in.
 *      The merchant sees the block in place and believes it is working.
 *
 *   2. Worse, the address is *recorded*. A visitor could send the IP of a rival shop's office,
 *      place a few abusive orders, and have the merchant block a third party who never
 *      visited. The fraud history a merchant makes decisions from was forgeable by the people
 *      it exists to catch.
 *
 * A header can only be believed when something in front of the site is known to have set it,
 * which is a fact about the merchant's hosting that no amount of inspection here can
 * establish. So the default is REMOTE_ADDR — the address the TCP connection actually came
 * from, which cannot be forged — and a merchant behind Cloudflare or a load balancer names
 * the header they trust, once, in settings.
 *
 * A site that is behind a proxy and has not configured this gets no IP rather than a wrong
 * one. That is deliberate: IP features doing nothing is recoverable and is surfaced as an
 * admin notice, while IP features acting on forged data is worse than not having them.
 */
class Guardify_Client_IP {

    /** Option holding the header a merchant's proxy sets, e.g. HTTP_CF_CONNECTING_IP. */
    const OPTION = 'guardify_trusted_proxy_header';

    /**
     * Headers a merchant may nominate, keyed by $_SERVER name.
     *
     * An allow-list rather than free text: the value is read out of $_SERVER, and letting a
     * merchant type any key there is a way to end up reading something that is not an address
     * at all. Every entry is a header a real reverse proxy actually sets.
     *
     * A method rather than a const because the labels are translated, and a translated string
     * evaluated while the class file is being read would resolve before the text domain is
     * loaded on `init` — coming out in the source language whatever the site has installed.
     */
    public static function allowed() {
        return [
            'HTTP_CF_CONNECTING_IP' => 'Cloudflare (CF-Connecting-IP)',
            'HTTP_X_REAL_IP'        => 'Nginx / LiteSpeed (X-Real-IP)',
            'HTTP_X_FORWARDED_FOR'  => __('সাধারণ প্রক্সি (X-Forwarded-For)', 'guardify-pro'),
            'HTTP_TRUE_CLIENT_IP'   => 'Akamai / Cloudflare Enterprise (True-Client-IP)',
        ];
    }

    /**
     * The visitor's address, or '' when it cannot be established honestly.
     *
     * @return string
     */
    public static function get() {
        $header = self::trusted_header();

        if ($header !== '' && !empty($_SERVER[$header])) {
            $value = sanitize_text_field(wp_unslash($_SERVER[$header]));

            // X-Forwarded-For is a chain: "client, proxy1, proxy2". The left-most entry is
            // the client as the first proxy saw it — and is also the part the client itself
            // can prepend, which is why this whole branch is opt-in.
            if (strpos($value, ',') !== false) {
                $value = trim(explode(',', $value)[0]);
            }

            if (self::is_public($value)) {
                return $value;
            }
        }

        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return self::is_public($remote) ? $remote : '';
    }

    /**
     * The raw connecting address, whatever it is.
     *
     * Used where any stable value will do — rate limiting a form, keying a transient — and a
     * private address is still a perfectly good key. Never use this for blocking or for
     * anything a merchant will see and act on.
     */
    public static function connection_address() {
        $remote = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
    }

    /**
     * The header this site is configured to believe, or '' for none.
     */
    public static function trusted_header() {
        $header = (string) get_option(self::OPTION, '');

        /**
         * Filter the proxy header Guardify trusts for the visitor's address.
         *
         * For sites whose hosting is configured in code rather than through settings. The
         * same allow-list applies — returning an arbitrary $_SERVER key does nothing.
         *
         * @param string $header
         */
        $header = (string) apply_filters('guardify_trusted_proxy_header', $header);

        return isset(self::allowed()[$header]) ? $header : '';
    }

    /**
     * Whether IP-based features can work on this site at all.
     *
     * False means the connection address is private — the site sits behind a proxy or a
     * load balancer — and no trusted header has been nominated, so every IP lookup will come
     * back empty. Worth telling the merchant, because the alternative is a shop where IP
     * blocking is switched on, shows no error, and silently never blocks anybody.
     */
    public static function is_resolvable() {
        return self::get() !== '';
    }

    /**
     * Whether the site looks proxied but has not said which header to trust.
     */
    public static function needs_proxy_setup() {
        if (self::trusted_header() !== '') {
            return false;
        }

        $remote = self::connection_address();
        if ($remote === '') {
            return false;
        }

        // A public connecting address means the visitor reached PHP directly and nothing
        // needs configuring.
        return !self::is_public($remote);
    }

    /**
     * A routable address — not private, not loopback, not link-local.
     */
    private static function is_public($ip) {
        if (!is_string($ip) || $ip === '') {
            return false;
        }

        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
