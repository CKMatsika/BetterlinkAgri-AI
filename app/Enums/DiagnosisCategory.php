<?php

declare(strict_types=1);

namespace App\Enums;

enum DiagnosisCategory: string
{
    case PEST = 'pest';
    case DISEASE = 'disease';
    case NUTRIENT_DEFICIENCY = 'nutrient_deficiency';
    case NUTRIENT_TOXICITY = 'nutrient_toxicity';
    case WEED = 'weed';
    case ABIOTIC_STRESS = 'abiotic_stress';
    case LIVESTOCK_CONDITION = 'livestock_condition';
    case UNCLEAR = 'unclear';

    public function defaultFollowUpDays(): int
    {
        return match ($this) {
            self::PEST, self::DISEASE, self::WEED => (int) config('betterlink.diagnosis.follow_up_days.pest', 7),
            self::NUTRIENT_DEFICIENCY, self::NUTRIENT_TOXICITY, self::ABIOTIC_STRESS => (int) config('betterlink.diagnosis.follow_up_days.nutrient_deficiency', 14),
            self::LIVESTOCK_CONDITION => (int) config('betterlink.diagnosis.follow_up_days.livestock_condition', 3),
            self::UNCLEAR => 7,
        };
    }
}
