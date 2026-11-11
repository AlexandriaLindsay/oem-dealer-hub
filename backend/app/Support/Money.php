<?php

namespace App\Support;

/**
 * Prices are whole cents everywhere (no floating-point rounding). This turns
 * what people type into cents, and back.
 */
final class Money
{
    /** Up to $10 million - a typo guard, not a business rule. */
    public const MAX_CENTS = 1_000_000_000;

    /**
     * "1234", "1,234.5", "$1,234.50" -> 123450. Null for anything that isn't
     * a plain non-negative amount with at most two decimals.
     */
    public static function toCents(string $text): ?int
    {
        $clean = str_replace([',', '$', ' '], '', trim($text));
        if (! preg_match('/^(\d{1,8})(?:\.(\d{1,2}))?$/', $clean, $m)) {
            return null;
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $cents <= self::MAX_CENTS ? $cents : null;
    }

    /** 123450 -> "1234.50" (for forms and JSON; the app formats for display). */
    public static function fromCents(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
