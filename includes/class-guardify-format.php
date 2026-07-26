<?php
defined('ABSPATH') || exit;

/**
 * Guardify_Format — Bengali number and label formatting.
 *
 * A shop in Bangladesh reads ৮৭%, not 87%. Mixing the two inside one screen is the
 * clearest sign of software that was translated rather than written for the market, and
 * this plugin is competing against products that get this right.
 *
 * These deliberately mirror portal/src/lib/format.js. The same order, scored by the same
 * engine, must not read differently depending on whether the merchant is looking at
 * wp-admin or the Guardify dashboard — a merchant who sees two numbers assumes one of them
 * is wrong, and then trusts neither.
 */
class Guardify_Format {

    /** Latin digit → Bengali digit, by position. */
    const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /**
     * Convert every Latin digit in a string to its Bengali form.
     *
     * Non-digits pass through untouched, so this is safe on a formatted string that
     * already carries separators or a unit.
     *
     * @param string|int|float $value
     * @return string
     */
    public static function bn($value) {
        return strtr((string) $value, [
            '0' => self::DIGITS[0], '1' => self::DIGITS[1], '2' => self::DIGITS[2],
            '3' => self::DIGITS[3], '4' => self::DIGITS[4], '5' => self::DIGITS[5],
            '6' => self::DIGITS[6], '7' => self::DIGITS[7], '8' => self::DIGITS[8],
            '9' => self::DIGITS[9],
        ]);
    }

    /**
     * A whole number in Bengali digits.
     *
     * @param mixed $value
     * @return string
     */
    public static function count($value) {
        if (!is_numeric($value)) {
            return '—';
        }
        return self::bn(number_format((float) $value, 0, '.', ''));
    }

    /**
     * A percentage in Bengali digits, including the sign.
     *
     * @param mixed $value    0-100.
     * @param int   $decimals
     * @return string
     */
    public static function percent($value, $decimals = 0) {
        if (!is_numeric($value)) {
            return '—';
        }
        return self::bn(number_format((float) $value, $decimals)) . '%';
    }

    /**
     * An amount in taka, grouped the way Bangladesh groups digits.
     *
     * Bengali follows the Indian system — ৳১,২৩,৪৫৬, not ৳123,456 — and a merchant
     * reading a total at a glance parses the wrong magnitude when the grouping is
     * Western.
     *
     * @param mixed $amount
     * @return string
     */
    public static function taka($amount) {
        if (!is_numeric($amount)) {
            return '—';
        }

        $amount   = (float) $amount;
        $sign     = $amount < 0 ? '-' : '';
        $whole    = (string) (int) round(abs($amount));

        // Last three digits, then pairs.
        if (strlen($whole) > 3) {
            $last  = substr($whole, -3);
            $rest  = substr($whole, 0, -3);
            $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $whole = $rest . ',' . $last;
        }

        return $sign . '৳' . self::bn($whole);
    }
}
