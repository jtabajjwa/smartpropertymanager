<?php

namespace App\Support;

/**
 * Countries an estate can be in, and Uganda's districts for the district
 * dropdown (other countries' districts are typed in).
 */
final class Places
{
    public const DEFAULT_COUNTRY = 'Uganda';

    public const COUNTRIES = [
        'Uganda', 'Kenya', 'Tanzania', 'Rwanda', 'Burundi', 'South Sudan', 'DR Congo', 'Somalia',
    ];

    /** Uganda's 135 districts and Kampala Capital City. */
    public const UGANDA_DISTRICTS = [
        'Abim', 'Adjumani', 'Agago', 'Alebtong', 'Amolatar', 'Amudat', 'Amuria', 'Amuru', 'Apac', 'Arua',
        'Budaka', 'Bududa', 'Bugiri', 'Bugweri', 'Buhweju', 'Buikwe', 'Bukedea', 'Bukomansimbi', 'Bukwo', 'Bulambuli',
        'Buliisa', 'Bundibugyo', 'Bunyangabu', 'Bushenyi', 'Busia', 'Butaleja', 'Butambala', 'Butebo', 'Buvuma', 'Buyende',
        'Dokolo', 'Gomba', 'Gulu', 'Hoima', 'Ibanda', 'Iganga', 'Isingiro', 'Jinja', 'Kaabong', 'Kabale',
        'Kabarole', 'Kaberamaido', 'Kagadi', 'Kakumiro', 'Kalaki', 'Kalangala', 'Kaliro', 'Kalungu', 'Kampala', 'Kamuli',
        'Kamwenge', 'Kanungu', 'Kapchorwa', 'Kapelebyong', 'Karenga', 'Kasese', 'Kassanda', 'Katakwi', 'Kayunga', 'Kazo',
        'Kibaale', 'Kiboga', 'Kibuku', 'Kikuube', 'Kiruhura', 'Kiryandongo', 'Kisoro', 'Kitagwenda', 'Kitgum', 'Koboko',
        'Kole', 'Kotido', 'Kumi', 'Kwania', 'Kween', 'Kyankwanzi', 'Kyegegwa', 'Kyenjojo', 'Kyotera', 'Lamwo',
        'Lira', 'Luuka', 'Luwero', 'Lwengo', 'Lyantonde', 'Madi-Okollo', 'Manafwa', 'Maracha', 'Masaka', 'Masindi',
        'Mayuge', 'Mbale', 'Mbarara', 'Mitooma', 'Mityana', 'Moroto', 'Moyo', 'Mpigi', 'Mubende', 'Mukono',
        'Nabilatuk', 'Nakapiripirit', 'Nakaseke', 'Nakasongola', 'Namayingo', 'Namisindwa', 'Namutumba', 'Napak', 'Nebbi', 'Ngora',
        'Ntoroko', 'Ntungamo', 'Nwoya', 'Obongi', 'Omoro', 'Otuke', 'Oyam', 'Pader', 'Pakwach', 'Pallisa',
        'Rakai', 'Rubanda', 'Rubirizi', 'Rukiga', 'Rukungiri', 'Rwampara', 'Sembabule', 'Serere', 'Sheema', 'Sironko',
        'Soroti', 'Terego', 'Tororo', 'Wakiso', 'Yumbe', 'Zombo',
    ];

    /** A Ugandan district as typed ("kampala district", "Wakiso ") → its listed name, or null if it isn't one. */
    public static function ugandaDistrict(?string $value): ?string
    {
        $key = mb_strtolower(trim(preg_replace('/\s+(district|city|capital city)$/i', '', trim((string) $value))));
        foreach (self::UGANDA_DISTRICTS as $district) {
            if (mb_strtolower($district) === $key) {
                return $district;
            }
        }

        return null;
    }
}
