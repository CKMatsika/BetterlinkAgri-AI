<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\MarketplaceRequestStatus;
use App\Models\AuditLog;
use App\Models\MarketplaceRequest;
use DomainException;

/**
 * Authoritative 14-state Marketplace Request Lifecycle State Machine.
 * Every transition is guard-checked and recorded in audit_logs.
 */
final class MarketplaceRequestStateMachine
{
    /**
     * @var array<string, list<MarketplaceRequestStatus>>
     */
    private const ALLOWED = [
        'DRAFT' => [
            MarketplaceRequestStatus::BROADCAST_RING_1,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'BROADCAST_RING_1' => [
            MarketplaceRequestStatus::OFFERS_RECEIVED,
            MarketplaceRequestStatus::BROADCAST_RING_2,
            MarketplaceRequestStatus::EXPIRED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'BROADCAST_RING_2' => [
            MarketplaceRequestStatus::OFFERS_RECEIVED,
            MarketplaceRequestStatus::BROADCAST_RING_3,
            MarketplaceRequestStatus::EXPIRED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'BROADCAST_RING_3' => [
            MarketplaceRequestStatus::OFFERS_RECEIVED,
            MarketplaceRequestStatus::UPSTREAM_ESCALATED,
            MarketplaceRequestStatus::EXPIRED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'OFFERS_RECEIVED' => [
            MarketplaceRequestStatus::BROADCAST_RING_2,
            MarketplaceRequestStatus::BROADCAST_RING_3,
            MarketplaceRequestStatus::UPSTREAM_ESCALATED,
            MarketplaceRequestStatus::OFFER_ACCEPTED,
            MarketplaceRequestStatus::EXPIRED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'UPSTREAM_ESCALATED' => [
            MarketplaceRequestStatus::OFFERS_RECEIVED,
            MarketplaceRequestStatus::UNFULFILLED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'OFFER_ACCEPTED' => [
            MarketplaceRequestStatus::FULFILMENT,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'FULFILMENT' => [
            MarketplaceRequestStatus::COMPLETED,
            MarketplaceRequestStatus::DISPUTED,
        ],
        'DISPUTED' => [
            MarketplaceRequestStatus::COMPLETED,
            MarketplaceRequestStatus::CANCELLED,
        ],
        'COMPLETED' => [
            MarketplaceRequestStatus::RATED,
        ],
        'RATED' => [],
        'EXPIRED' => [],
        'UNFULFILLED' => [],
        'CANCELLED' => [],
    ];

    /**
     * @param array<string, mixed> $metadata
     */
    public function transitionTo(
        MarketplaceRequest $request,
        MarketplaceRequestStatus $target,
        string $reason,
        array $metadata = []
    ): void {
        $from = $request->status instanceof MarketplaceRequestStatus
            ? $request->status
            : MarketplaceRequestStatus::from((string) $request->status);

        $allowed = self::ALLOWED[$from->value] ?? [];
        if ($from !== $target && !in_array($target, $allowed, true)) {
            throw new DomainException(
                "Illegal marketplace request transition from {$from->value} to {$target->value} (reason: {$reason})."
            );
        }

        $request->update(['status' => $target]);

        AuditLog::query()->create([
            'tenant_id' => $request->origin_organisation_id,
            'conversation_id' => $request->conversation_id,
            'event_type' => 'marketplace_request_transition',
            'from_state' => $from->value,
            'to_state' => $target->value,
            'metadata_json' => array_merge([
                'request_id' => $request->id,
                'reason' => $reason,
            ], $metadata),
            'created_at' => now(),
        ]);
    }
}
