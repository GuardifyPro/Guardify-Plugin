<?php
defined('ABSPATH') || exit;

/**
 * Guardify Phone Utility — Centralized phone normalization and validation
 * for Bangladesh phone numbers (01X-XXXXXXXX format).
 */
class Guardify_Phone_Util {

    /**
     * Normalize a phone number to 01XXXXXXXXX (11 digit) format.
     * Strips spaces, dashes, parentheses, and +88/88 prefix.
     *
     * @param string $phone Raw phone number.
     * @return string Normalized phone (may be invalid — call validate() to check).
     */
    public static function normalize($phone) {
        $phone = preg_replace('/[\s\-\(\)]/', '', (string) $phone);
        $phone = preg_replace('/^\+?880?/', '', $phone);
        // If starts with "0", keep as-is; if just 10 digits starting with 1, prepend "0"
        if (preg_match('/^1[3-9]\d{8}$/', $phone)) {
            $phone = '0' . $phone;
        }
        return $phone;
    }

    /**
     * Validate a normalized Bangladesh phone number.
     *
     * @param string $phone Normalized phone number.
     * @return bool True if valid 01X format.
     */
    public static function validate($phone) {
        return (bool) preg_match('/^01[3-9]\d{8}$/', $phone);
    }

    /**
     * Normalize and validate in one step. Returns normalized phone or null.
     *
     * @param string $phone Raw phone number.
     * @return string|null Normalized phone or null if invalid.
     */
    public static function clean($phone) {
        $normalized = self::normalize($phone);
        return self::validate($normalized) ? $normalized : null;
    }

    /**
     * Get all common variations of a phone number for DB lookups.
     *
     * @param string $phone Raw or normalized phone.
     * @return array Array of phone variations [01X..., 8801X..., +8801X...].
     */
    public static function variations($phone) {
        $normalized = self::normalize($phone);
        return [$normalized, '88' . $normalized, '+88' . $normalized];
    }
}
