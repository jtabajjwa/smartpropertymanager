<?php

namespace App\Support;

use App\Models\Landlord;
use App\Models\LandlordNumberSequence;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Unit IDs: digits only so they're easy to key in on USSD, and unique across
 * the platform. Built as landlord number + the unit's running number within
 * that landlord (at least 3 digits) + a Luhn check digit, e.g. landlord 100's
 * 12th unit is 1000124. The check digit catches any single mistyped digit and
 * most swapped pairs, so a typo doesn't land on someone else's unit.
 */
class UnitCode
{
    public const FIRST_LANDLORD_NUMBER = 100;

    public const SEQUENCE_KEY = 'unit_code';

    public static function make(int $landlordNumber, int $seq): string
    {
        $body = $landlordNumber.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    /** Digits typed by a tenant, with spaces or dashes removed; null unless it's a well-formed unit ID. */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s\-.]/', '', $input);

        return preg_match('/^\d{7,15}$/', $digits) && self::isValid($digits) ? $digits : null;
    }

    public static function isValid(string $code): bool
    {
        return strlen($code) > 1 && self::checkDigit(substr($code, 0, -1)) === substr($code, -1);
    }

    public static function checkDigit(string $digits): string
    {
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $d = (int) $d;
            if ($i % 2 === 0) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return (string) ((10 - $sum % 10) % 10);
    }

    /** The next free landlord number. */
    public static function nextLandlordNumber(): int
    {
        return max(self::FIRST_LANDLORD_NUMBER, (int) Landlord::max('number') + 1);
    }

    /** A new, unused ID for one of this landlord's units. */
    public static function nextFor(int $landlordId): string
    {
        return DB::transaction(function () use ($landlordId) {
            $number = Landlord::whereKey($landlordId)->value('number');
            if (!$number) {
                $number = self::nextLandlordNumber();
                Landlord::whereKey($landlordId)->update(['number' => $number]);
            }

            $row = LandlordNumberSequence::lockForUpdate()->firstOrCreate(
                ['landlord_id' => $landlordId, 'sequence_key' => self::SEQUENCE_KEY],
                ['next_number' => 1]
            );

            $seq = $row->next_number;
            do {
                $code = self::make($number, $seq++);
            } while (Unit::withoutGlobalScopes()->where('code', $code)->exists());

            $row->update(['next_number' => $seq]);

            return $code;
        });
    }
}
