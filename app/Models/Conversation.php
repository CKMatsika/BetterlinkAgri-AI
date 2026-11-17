<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Enums\LanguageCode;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'channel',
        'external_thread_id',
        'state',
        'mode',
        'language',
        'context_chip',
        'poor_photo_retries',
        'pending_message_buffer_json',
        'context_metadata_json',
        'assigned_user_id',
        'state_updated_at',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'state' => ConversationState::class,
            'mode' => ConversationMode::class,
            'language' => LanguageCode::class,
            'poor_photo_retries' => 'integer',
            'pending_message_buffer_json' => 'array',
            'context_metadata_json' => 'array',
            'state_updated_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function refreshContextChip(?string $cropName = null, ?string $growthStage = null, ?string $condition = null): void
    {
        $meta = $this->context_metadata_json ?? [];
        if ($cropName !== null) {
            $meta['crop'] = $cropName;
        }
        if ($growthStage !== null) {
            $meta['stage'] = $growthStage;
        }
        if ($condition !== null) {
            $meta['condition'] = $condition;
        }

        $parts = array_filter([
            $meta['crop'] ?? null,
            $meta['stage'] ?? null,
            $meta['condition'] ?? null,
        ]);

        $this->update([
            'context_metadata_json' => $meta,
            'context_chip' => $parts !== [] ? implode(' / ', $parts) : $this->context_chip,
        ]);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(Diagnosis::class);
    }
}
