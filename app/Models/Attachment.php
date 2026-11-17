<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'message_id',
        'disk',
        'file_path',
        'mime_type',
        'file_size_bytes',
        'sha256_hash',
        'width',
        'height',
        'blur_score',
        'brightness_score',
        'quality_passed',
        'quality_failure_reason',
        'exif_stripped',
    ];

    protected function casts(): array
    {
        return [
            'file_size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'blur_score' => 'decimal:2',
            'brightness_score' => 'decimal:2',
            'quality_passed' => 'boolean',
            'exif_stripped' => 'boolean',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
