<?php

declare(strict_types=1);

namespace App\Enums;

enum NaturalRegion: string
{
    case I = 'I';
    case IIA = 'IIa';
    case IIB = 'IIb';
    case III = 'III';
    case IV = 'IV';
    case V = 'V';

    public function annualRainfallMm(): string
    {
        return match ($this) {
            self::I => '> 1000 mm',
            self::IIA => '750 - 1000 mm (Reliable)',
            self::IIB => '750 - 1000 mm (Subject to dry spells)',
            self::III => '650 - 800 mm (Mid-season dry spells common)',
            self::IV => '450 - 650 mm (Semi-arid, periodic seasonal drought)',
            self::V => '< 450 mm (Arid / Lowveld, erratic rainfall)',
        };
    }

    public function recommendedMaizeMaturityGroup(): string
    {
        return match ($this) {
            self::I, self::IIA => 'Late / Medium-to-Late Maturity (140-155 days)',
            self::IIB => 'Medium Maturity (130-140 days)',
            self::III => 'Early-to-Medium Maturity (120-135 days)',
            self::IV, self::V => 'Ultra-Early / Early Drought-Tolerant Maturity (110-125 days) or Small Grains (Sorghum/Millet)',
        };
    }
}
