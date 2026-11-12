<?php

namespace App\Support;

/**
 * Shillings in words, as written on a receipt:
 * 1250000 → "One million two hundred fifty thousand shillings only".
 * Uganda shillings have no cents in practice, so the amount is rounded.
 */
final class AmountInWords
{
    private const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten',
        'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    private const SCALES = [1_000_000_000_000 => 'trillion', 1_000_000_000 => 'billion', 1_000_000 => 'million', 1_000 => 'thousand'];

    public static function shillings(float|int|string $amount): string
    {
        $n = (int) round((float) $amount);

        return ucfirst(($n === 0 ? 'zero' : self::words($n)).' shillings only');
    }

    public static function words(int $n): string
    {
        if ($n < 0) {
            return 'minus '.self::words(-$n);
        }

        $parts = [];
        foreach (self::SCALES as $size => $name) {
            if ($n >= $size) {
                $parts[] = self::hundreds(intdiv($n, $size)).' '.$name;
                $n %= $size;
            }
        }
        if ($n > 0) {
            $parts[] = self::hundreds($n);
        }

        return implode(' ', $parts);
    }

    /** 1–999 */
    private static function hundreds(int $n): string
    {
        $out = [];
        if ($n >= 100) {
            $out[] = self::ONES[intdiv($n, 100)].' hundred';
            $n %= 100;
        }
        if ($n >= 20) {
            $out[] = self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');
        } elseif ($n > 0) {
            $out[] = self::ONES[$n];
        }

        return implode(' ', $out);
    }
}
