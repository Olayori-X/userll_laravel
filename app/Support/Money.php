<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * All money in the system is an integer number of kobo (NGN 1 = 100 kobo).
 * These helpers convert at the edges only (input from / output to people).
 */
final class Money
{
    /**
     * Parse human input into kobo. Accepts "100500", "100,500.50", "₦1,250",
     * and shorthand like "100.50k" (= 100,500.00) or "1.5m".
     */
    public static function fromNaira(string|int|float $input): int
    {
        $s = strtolower(str_replace([',', '₦', ' '], '', trim((string) $input)));

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?([km])?$/', $s, $m)) {
            throw new InvalidArgumentException("Invalid amount: {$input}");
        }

        $kobo = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return match ($m[3] ?? '') {
            'k' => $kobo * 1_000,
            'm' => $kobo * 1_000_000,
            default => $kobo,
        };
    }

    /** Same as fromNaira() but returns null instead of throwing (handy for validation). */
    public static function tryFromNaira(mixed $input): ?int
    {
        if (! is_scalar($input)) {
            return null;
        }

        try {
            return self::fromNaira($input);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** 10050000 => "₦100,500.00" */
    public static function naira(int $kobo): string
    {
        return '₦'.number_format($kobo / 100, 2);
    }

    /** 10050000 => "₦100.50k", 150000000 => "₦1.50M", 95000 => "₦950.00" */
    public static function short(int $kobo): string
    {
        $naira = $kobo / 100;

        return match (true) {
            $naira >= 1_000_000 => '₦'.number_format($naira / 1_000_000, 2).'M',
            $naira >= 1_000 => '₦'.number_format($naira / 1_000, 2).'k',
            default => '₦'.number_format($naira, 2),
        };
    }

    /** Commission in kobo for a basis-point rate (500 bps = 5%), rounded half up. */
    public static function commission(int $amountKobo, int $rateBps): int
    {
        return intdiv($amountKobo * $rateBps + 5_000, 10_000);
    }
}
