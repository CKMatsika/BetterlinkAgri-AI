<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Services\Marketplace\MarketplaceFromDiagnosisAndPlanService;

/**
 * Deterministic AI Tool #14: create_marketplace_request.
 * Converts a confirmed diagnosis product & calculated quantity into a Ring-1 marketplace request.
 */
final class CreateMarketplaceRequestTool implements AiToolContract
{
    public function __construct(
        private readonly MarketplaceFromDiagnosisAndPlanService $service
    ) {
    }

    public function name(): string
    {
        return 'create_marketplace_request';
    }

    public function description(): string
    {
        return 'Create a live marketplace request from a diagnosis advisory and broadcast to nearby retail outlets in Ring 1.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'diagnosis_id' => ['type' => 'string'],
                'item_name' => ['type' => 'string'],
                'product_id' => ['type' => 'string'],
                'quantity_scaled' => ['type' => 'integer'],
                'unit' => ['type' => 'string'],
                'latitude' => ['type' => 'number'],
                'longitude' => ['type' => 'number'],
                'currency' => ['type' => 'string'],
            ],
            'required' => ['item_name', 'quantity_scaled', 'unit'],
        ];
    }

    public function execute(Conversation $conversation, array $arguments): array
    {
        $diagnosis = !empty($arguments['diagnosis_id'])
            ? Diagnosis::withoutGlobalScopes()->find((string) $arguments['diagnosis_id'])
            : $conversation->diagnoses()->latest()->first();

        $req = $this->service->createFromDiagnosis(
            conversation: $conversation,
            diagnosis: $diagnosis,
            items: [[
                'product_id' => isset($arguments['product_id']) ? (string) $arguments['product_id'] : null,
                'item_name' => (string) $arguments['item_name'],
                'quantity_scaled' => (int) $arguments['quantity_scaled'],
                'unit' => (string) $arguments['unit'],
            ]],
            lat: (float) ($arguments['latitude'] ?? -17.8292),
            lng: (float) ($arguments['longitude'] ?? 31.0522),
            currency: (string) ($arguments['currency'] ?? 'USD')
        );

        return [
            'request_id' => $req->id,
            'status' => $req->status->value,
            'current_ring' => $req->current_ring,
        ];
    }
}
