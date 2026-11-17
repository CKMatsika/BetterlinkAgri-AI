<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Conversation;
use App\Services\Rag\TenantRagService;

final class SearchKnowledgeTool implements AiToolContract
{
    public function __construct(
        private readonly TenantRagService $ragService
    ) {
    }

    public function name(): string
    {
        return 'search_knowledge';
    }

    public function description(): string
    {
        return 'Search the tenant-scoped approved agronomy knowledge base, product labels, and pest/nutrient guides.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query for agronomy or product knowledge'],
                'crop_code' => ['type' => 'string', 'description' => 'Optional crop code filter (e.g. maize, tomatoes)'],
                'document_type' => ['type' => 'string', 'description' => 'Optional document type filter'],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $chunks = $this->ragService->search(
            tenant: $conversation->tenant,
            query: (string) $input['query'],
            cropCode: isset($input['crop_code']) ? (string) $input['crop_code'] : null,
            documentType: isset($input['document_type']) ? (string) $input['document_type'] : null,
            topK: (int) config('betterlink.diagnosis.rag_top_k', 5)
        );

        return ['chunks' => $chunks];
    }
}
