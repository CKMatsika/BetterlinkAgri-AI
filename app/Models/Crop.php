<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Crop extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name_en',
        'name_sn',
        'name_nd',
        'season_type',
        'default_row_spacing_cm',
        'default_inrow_spacing_cm',
        'pfumvudza_supported',
    ];

    protected function casts(): array
    {
        return [
            'default_row_spacing_cm' => 'integer',
            'default_inrow_spacing_cm' => 'integer',
            'pfumvudza_supported' => 'boolean',
        ];
    }

    public function localizedName(LanguageCode $lang): string
    {
        return match ($lang) {
            LanguageCode::SHONA => $this->name_sn ?: $this->name_en,
            LanguageCode::NDEBELE => $this->name_nd ?: $this->name_en,
            LanguageCode::ENGLISH => $this->name_en,
        };
    }

    public function defaultPlantPopulationPerHa(): int
    {
        $rowMeters = max(0.10, $this->default_row_spacing_cm / 100.0);
        $inRowMeters = max(0.05, $this->default_inrow_spacing_cm / 100.0);

        return (int) round(10000.0 / ($rowMeters * $inRowMeters));
    }
}
