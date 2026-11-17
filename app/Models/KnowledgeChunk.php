<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'product_document_id',
        'document_type',
        'crop_code',
        'language',
        'title',
        'chunk_index',
        'page_number',
        'content',
        'embedding',
        'metadata_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'page_number' => 'integer',
            'metadata_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function productDocument(): BelongsTo
    {
        return $this->belongsTo(ProductDocument::class);
    }
}
