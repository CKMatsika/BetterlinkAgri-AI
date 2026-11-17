<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Livestock extends Model
{
    use HasUuids;

    protected $table = 'livestock';

    protected $fillable = [
        'code',
        'name_en',
        'name_sn',
        'name_nd',
        'category',
    ];

    public function localizedName(LanguageCode $lang): string
    {
        return match ($lang) {
            LanguageCode::SHONA => $this->name_sn ?: $this->name_en,
            LanguageCode::NDEBELE => $this->name_nd ?: $this->name_en,
            LanguageCode::ENGLISH => $this->name_en,
        };
    }
}
