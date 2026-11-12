<?php

namespace App\Support;

/**
 * tel: / WhatsApp links for Philippine numbers however they were typed:
 * "0918 555 0101", "+63 918 555 0101", "(+63) 977-815-0888", "63 994 895 0669",
 * "918 555 0101". Used by the email templates; the website chat has the same
 * rule in src/utils/phoneLink.ts.
 */
class PhoneLink
{
    /** International digits without "+" (e.g. "639185550101"), or the raw digits if not a PH mobile/landline shape. */
    public static function international(string $number): string
    {
        $d = preg_replace('/\D/', '', $number);

        return match (true) {
            str_starts_with($d, '63') && strlen($d) >= 11 => $d,
            str_starts_with($d, '0') && strlen($d) >= 10 => '63'.substr($d, 1),
            strlen($d) === 10 && str_starts_with($d, '9') => '63'.$d,
            default => $d,
        };
    }

    /** "tel:+639185550101" — with "+" so it dials from any phone, not as a local number. */
    public static function tel(string $number): string
    {
        $i = self::international($number);

        return 'tel:'.(str_starts_with($i, '63') ? '+'.$i : $i);
    }

    /** "https://wa.me/639185550101" — wa.me needs the country code, no "+" or zeros. */
    public static function whatsapp(string $number): string
    {
        return 'https://wa.me/'.self::international($number);
    }
}
