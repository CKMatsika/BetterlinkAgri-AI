<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'diagnosis_id',
        'user_id',
        'event_type',
        'from_state',
        'to_state',
        'attachment_sha256',
        'candidates_json',
        'questions_json',
        'tool_calls_json',
        'rag_chunk_ids_json',
        'guardrail_result_json',
        'metadata_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'candidates_json' => 'array',
            'questions_json' => 'array',
            'tool_calls_json' => 'array',
            'rag_chunk_ids_json' => 'array',
            'guardrail_result_json' => 'array',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }
}
