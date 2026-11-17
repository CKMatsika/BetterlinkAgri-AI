<?php

declare(strict_types=1);

namespace App\Services\Localization;

use App\Enums\LanguageCode;

/**
 * Zimbabwean Trilingual Agronomy Glossary (English, ChiShona, isiNdebele)
 * and Per-Message Language Detector.
 *
 * TRANSLATION STATUS:
 * - 'en': reviewed
 * - 'sn' (ChiShona): NEEDS NATIVE REVIEW (gated by betterlink.localization.enable_sn_nd)
 * - 'nd' (isiNdebele): NEEDS NATIVE REVIEW (gated by betterlink.localization.enable_sn_nd)
 */
final class ZimbabweAgronomyGlossary
{
    /**
     * @var array<string, array{en: string, sn: string, nd: string}>
     */
    private const PEST_AND_DISEASE_GLOSSARY = [
        'fall_armyworm' => [
            'en' => 'Fall Armyworm (Spodoptera frugiperda)',
            'sn' => 'Gonye remunhava / Honye yechibage',
            'nd' => 'Amacimbi / Impethu yomumbu',
        ],
        'maize_stalk_borer' => [
            'en' => 'African Maize Stalk Borer (Busseola fusca)',
            'sn' => 'Chiburi / Gonye redzinde',
            'nd' => 'Isibungu somumbu',
        ],
        'aphids' => [
            'en' => 'Aphids (Plant Lice)',
            'sn' => 'Inda dzemuriwo / Inda dzembesa',
            'nd' => 'Inda zemibhida',
        ],
        'red_spider_mite' => [
            'en' => 'Red Spider Mite (Tetranychus evansi)',
            'sn' => 'Karikuni /Chitsvuku chemashizha',
            'nd' => 'Utulwa olubomvu',
        ],
        'whitefly' => [
            'en' => 'Whitefly (Bemisia tabaci)',
            'sn' => 'Tuvhunzi tuchena (Whiteflies)',
            'nd' => 'Impukane ezimhlophe',
        ],
        'cutworm' => [
            'en' => 'Cutworms (Agrotis spp.)',
            'sn' => 'Honye dzinocheka mbesa (Cheka-cheka)',
            'nd' => 'Izibungu eziquma izilimo',
        ],
        'bollworm' => [
            'en' => 'African Bollworm (Helicoverpa armigera)',
            'sn' => 'Gonye redonje nemadomasi (Bollworm)',
            'nd' => 'Impethu yotshinda lamatamatisi',
        ],
        'tuta_absoluta' => [
            'en' => 'Tomato Leaf Miner (Tuta absoluta)',
            'sn' => 'Tuta / Honye inopinda mumashizha emadomasi',
            'nd' => 'Impethu yamatamatisi (Tuta absoluta)',
        ],
        'maize_streak_virus' => [
            'en' => 'Maize Streak Virus (MSV)',
            'sn' => 'Chirwere chemitsetse yeyero pachibage (Streak)',
            'nd' => 'Umkhuhlane wemizila yomumbu (Streak)',
        ],
        'grey_leaf_spot' => [
            'en' => 'Grey Leaf Spot (Cercospora zeae-maydis)',
            'sn' => 'Chitaka chemashizha (Grey Leaf Spot)',
            'nd' => 'Amabala empunga emahlamvwini',
        ],
        'maize_rust' => [
            'en' => 'Common & Southern Maize Rust',
            'sn' => 'Ngura yechibage (Rust)',
            'nd' => 'Ukugwala komumbu (Rust)',
        ],
        'early_and_late_blight' => [
            'en' => 'Early & Late Blight (Alternaria / Phytophthora)',
            'sn' => 'Chando / Blight yemadomasi',
            'nd' => 'I-Blight yamatamatisi',
        ],
        'bacterial_wilt' => [
            'en' => 'Bacterial / Fusarium Wilt',
            'sn' => 'Kusvava kwemuti (Wilt)',
            'nd' => 'Ukubuna kwesilimo (Wilt)',
        ],
        'frogeye_leaf_spot' => [
            'en' => 'Frogeye Leaf Spot (Cercospora sojina)',
            'sn' => 'Ziso redafi pasoya (Frogeye Leaf Spot)',
            'nd' => 'Ihlo lexoxo kusoya',
        ],
        'nitrogen_deficiency' => [
            'en' => 'Nitrogen (N) Deficiency',
            'sn' => 'Kushomeka kweNitrogen (mashizha ezasi anoita yero V)',
            'nd' => 'Ukusilela kwe-Nitrogen (amahlamvu angaphansi aba luthanga)',
        ],
        'phosphorus_deficiency' => [
            'en' => 'Phosphorus (P) Deficiency',
            'sn' => 'Kushomeka kwePhosphorus (mashizha anoita pepuru/ruvara rwechidhumbu)',
            'nd' => 'Ukusilela kwe-Phosphorus (amahlamvu aba yi-purple)',
        ],
        'potassium_deficiency' => [
            'en' => 'Potassium (K) Deficiency',
            'sn' => 'Kushomeka kwePotassium (kutsva kwemipendero yemashizha ezasi)',
            'nd' => 'Ukusilela kwe-Potassium (ukutsha kwemiphetho yamahlamvu)',
        ],
        'theileriosis_january_disease' => [
            'en' => 'Cattle Theileriosis (January Disease)',
            'sn' => 'Chirwere cheJanuary Disease (Chigwere chehumba)',
            'nd' => 'Umkhuhlane weJanuary Disease (Amankwahla)',
        ],
    ];

    /**
     * Detect whether an incoming farmer message is written in ChiShona (sn), isiNdebele (nd), or English (en).
     */
    public function detectLanguage(?string $text, LanguageCode $fallback = LanguageCode::ENGLISH): LanguageCode
    {
        if ($text === null || trim($text) === '') {
            return $fallback;
        }

        $lower = strtolower($text);

        $shonaTokens = [
            'mhoro', 'mhoroi', 'maswera', 'makadii', 'ndapota', 'chibage', 'mashizha',
            'gonye', 'honye', 'munda', 'fetiraiza', 'mushonga', 'madomasi', 'fodya',
            'mombe', 'mbudzi', 'huku', 'hongu', 'kwete', 'mira', 'tanga', 'ndiani',
            'zvakadii', 'iri kuita', 'yero', 'kutsva', 'mudhumeni',
        ];

        $ndebeleTokens = [
            'sawubona', 'salibonani', 'linjani', 'sicela', 'umumbu', 'amahlamvu',
            'amacimbi', 'impethu', 'insimu', 'umuthi', 'amatamatisi', 'inkomo',
            'imbuzi', 'inkukhu', 'yebo', 'hatshi', 'mana', 'qala', 'umlimisi',
            'kakhulu', 'isilimo', 'isibungu',
        ];

        $shonaScore = 0;
        foreach ($shonaTokens as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $lower) === 1) {
                $shonaScore++;
            }
        }

        $ndebeleScore = 0;
        foreach ($ndebeleTokens as $token) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/u', $lower) === 1) {
                $ndebeleScore++;
            }
        }

        if ($shonaScore > $ndebeleScore && $shonaScore >= 1) {
            return LanguageCode::SHONA;
        }

        if ($ndebeleScore > $shonaScore && $ndebeleScore >= 1) {
            return LanguageCode::NDEBELE;
        }

        return $fallback;
    }

    /**
     * Return local names {'sn': '...', 'nd': '...'} for a candidate code or name.
     *
     * @return array{sn: string, nd: string}
     */
    public function localNamesFor(string $codeOrName): array
    {
        $normalized = strtolower(trim(str_replace([' ', '-'], '_', $codeOrName)));

        if (isset(self::PEST_AND_DISEASE_GLOSSARY[$normalized])) {
            return [
                'sn' => self::PEST_AND_DISEASE_GLOSSARY[$normalized]['sn'],
                'nd' => self::PEST_AND_DISEASE_GLOSSARY[$normalized]['nd'],
            ];
        }

        foreach (self::PEST_AND_DISEASE_GLOSSARY as $key => $entry) {
            if (str_contains($normalized, $key) || str_contains(strtolower($entry['en']), $normalized)) {
                return [
                    'sn' => $entry['sn'],
                    'nd' => $entry['nd'],
                ];
            }
        }

        return [
            'sn' => $codeOrName,
            'nd' => $codeOrName,
        ];
    }

    /**
     * Return localized WhatsApp advisory section headings.
     *
     * @return array{what_it_is: string, what_to_do_now: string, product_options: string, how_to_apply: string, safety: string, next_step: string}
     */
    public function advisoryHeadings(LanguageCode $lang): array
    {
        return match ($lang) {
            LanguageCode::SHONA => [
                'what_it_is' => '*Chii chiri kunetsa (What it is):*',
                'what_to_do_now' => '*Zvekuita izvozvi (What to do now):*',
                'product_options' => '*Mishonga yakatenderwa (Product options):*',
                'how_to_apply' => '*Mashandisirwo nemwero (How to apply):*',
                'safety' => '*Chenjedzo nekuzvidzivirira (Safety):*',
                'next_step' => '*Danho rinotevera (Next step):*',
            ],
            LanguageCode::NDEBELE => [
                'what_it_is' => '*Okuhluphayo (What it is):*',
                'what_to_do_now' => '*Okumele kwenziwe khathesi (What to do now):*',
                'product_options' => '*Imithi evunyelweyo (Product options):*',
                'how_to_apply' => '*Indlela yokusebenzisa (How to apply):*',
                'safety' => '*Ukuvikela ingozi (Safety):*',
                'next_step' => '*Isinyathelo esilandelayo (Next step):*',
            ],
            LanguageCode::ENGLISH => [
                'what_it_is' => '*What it is:*',
                'what_to_do_now' => '*What to do now:*',
                'product_options' => '*Product options:*',
                'how_to_apply' => '*How to apply:*',
                'safety' => '*Safety:*',
                'next_step' => '*Next step:*',
            ],
        };
    }
}
