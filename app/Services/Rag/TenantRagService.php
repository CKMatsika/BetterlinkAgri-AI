<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Contracts\EmbeddingProviderContract;
use App\Enums\RecommendationScope;
use App\Models\KnowledgeChunk;
use App\Models\Tenant;
use App\Models\TenantSupplyLink;
use Illuminate\Support\Facades\DB;

/**
 * Tenant-scoped RAG Retrieval Service over approved product labels, pest/disease guides,
 * nutrient guides, and fertiliser recommendation tables.
 */
final class TenantRagService
{
    public function __construct(
        private readonly EmbeddingProviderContract $embeddingProvider
    ) {
    }

    /**
     * Search tenant-approved knowledge chunks (plus active supply-link manufacturer chunks if allowed).
     *
     * @return list<array{chunk_id: string, title: string, document_type: string, page_number: ?int, content: string, similarity: float}>
     */
    public function search(
        Tenant $tenant,
        string $query,
        ?string $cropCode = null,
        ?string $documentType = null,
        int $topK = 5
    ): array {
        $allowedTenantIds = [$tenant->id];

        if ($tenant->recommendation_scope === RecommendationScope::OWN_PLUS_PARTNER_BRANDS) {
            $partnerIds = TenantSupplyLink::query()
                ->where('distributor_or_retailer_tenant_id', '=', $tenant->id)
                ->where('status', '=', 'active')
                ->pluck('manufacturer_tenant_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $allowedTenantIds = array_values(array_unique(array_merge($allowedTenantIds, $partnerIds)));
        }

        // Use pgvector cosine distance if running on PostgreSQL with populated vector column
        if (DB::getDriverName() === 'pgsql') {
            $queryVector = $this->embeddingProvider->embedText($query);
            $vectorLiteral = '[' . implode(',', $queryVector) . ']';

            $hasEmbeddedRows = KnowledgeChunk::withoutGlobalScopes()
                ->whereIn('tenant_id', $allowedTenantIds)
                ->where('is_active', '=', true)
                ->whereNotNull('embedding')
                ->exists();

            if ($hasEmbeddedRows) {
                $sqlQuery = KnowledgeChunk::withoutGlobalScopes()
                    ->select([
                        'id',
                        'title',
                        'document_type',
                        'page_number',
                        'content',
                        DB::raw("1 - (embedding <=> '{$vectorLiteral}'::vector) AS similarity"),
                    ])
                    ->whereIn('tenant_id', $allowedTenantIds)
                    ->where('is_active', '=', true)
                    ->whereNotNull('embedding');

                if ($cropCode !== null && $cropCode !== '') {
                    $sqlQuery->where(function ($q) use ($cropCode): void {
                        $q->where('crop_code', '=', $cropCode)->orWhereNull('crop_code');
                    });
                }

                if ($documentType !== null && $documentType !== '') {
                    $sqlQuery->where('document_type', '=', $documentType);
                }

                $rows = $sqlQuery
                    ->orderByRaw("embedding <=> '{$vectorLiteral}'::vector ASC")
                    ->limit($topK)
                    ->get();

                return $rows->map(fn ($row) => [
                    'chunk_id' => (string) $row->id,
                    'title' => (string) $row->title,
                    'document_type' => (string) $row->document_type,
                    'page_number' => $row->page_number !== null ? (int) $row->page_number : null,
                    'content' => (string) $row->content,
                    'similarity' => round((float) $row->similarity, 4),
                ])->all();
            }
        }

        // Deterministic lexical overlap scoring fallback (when embeddings are null or in SQLite test runs)
        $builder = KnowledgeChunk::withoutGlobalScopes()
            ->whereIn('tenant_id', $allowedTenantIds)
            ->where('is_active', '=', true);

        if ($cropCode !== null && $cropCode !== '') {
            $builder->where(function ($q) use ($cropCode): void {
                $q->where('crop_code', '=', $cropCode)->orWhereNull('crop_code');
            });
        }

        if ($documentType !== null && $documentType !== '') {
            $builder->where('document_type', '=', $documentType);
        }

        $candidates = $builder->get();
        $queryTokens = array_unique(
            preg_split('/[^a-z0-9_]+/i', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: []
        );

        $scored = [];
        foreach ($candidates as $chunk) {
            $haystack = strtolower($chunk->title . ' ' . $chunk->content . ' ' . json_encode($chunk->metadata_json));
            $matches = 0;
            foreach ($queryTokens as $token) {
                if (strlen($token) >= 2 && str_contains($haystack, $token)) {
                    $matches++;
                }
            }
            $similarity = count($queryTokens) > 0
                ? min(0.99, 0.50 + (0.49 * ($matches / count($queryTokens))))
                : 0.75;

            $scored[] = [
                'chunk_id' => (string) $chunk->id,
                'title' => (string) $chunk->title,
                'document_type' => (string) $chunk->document_type,
                'page_number' => $chunk->page_number !== null ? (int) $chunk->page_number : null,
                'content' => (string) $chunk->content,
                'similarity' => round($similarity, 4),
            ];
        }

        usort($scored, fn (array $a, array $b) => $b['similarity'] <=> $a['similarity']);

        return array_slice($scored, 0, $topK);
    }
}
