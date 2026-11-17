<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RichCardType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'direction',
        'sender_type',
        'external_message_id',
        'message_type',
        'card_type',
        'card_payload_json',
        'body_text',
        'transcribed_text',
        'detected_language',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'card_type' => RichCardType::class,
            'card_payload_json' => 'array',
            'metadata_json' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}
