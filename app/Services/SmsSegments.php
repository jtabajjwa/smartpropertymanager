<?php

namespace App\Services;

/**
 * How many SMS parts a message uses, the way networks bill it:
 *  - GSM-7 text: 160 characters in one part, 153 per part when split
 *    (characters like € [ ] { } count double);
 *  - anything else (emoji, many accented letters) is sent as UCS-2:
 *    70 characters in one part, 67 per part when split.
 */
final class SmsSegments
{
    private const GSM_BASIC = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM_EXTENDED = "^{}\[~]|€\f";

    public static function count(string $message): int
    {
        if ($message === '') {
            return 1;
        }

        $length = 0;
        $gsm = true;

        foreach (mb_str_split($message) as $char) {
            if (mb_strpos(self::GSM_BASIC, $char) !== false) {
                $length += 1;
            } elseif (mb_strpos(self::GSM_EXTENDED, $char) !== false) {
                $length += 2;
            } else {
                $gsm = false;
                break;
            }
        }

        if (!$gsm) {
            // UCS-2 counts UTF-16 units: an emoji takes two.
            $length = intdiv(strlen(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')), 2);

            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }
}
