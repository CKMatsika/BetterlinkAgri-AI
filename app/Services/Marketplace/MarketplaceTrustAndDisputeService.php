<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\MarketplaceRequestStatus;
use App\Models\AuditLog;
use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\OrderEvent;
use App\Models\Rating;
use App\Models\SellerVerification;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic M3 Trust, Mutual Ratings, Seller Verification & Dispute Resolution Service.
 */
final class MarketplaceTrustAndDisputeService
{
    public function __construct(
        private readonly MarketplaceRequestStateMachine $stateMachine
    ) {
    }

    public function isPlatformAdmin(User $user): bool
    {
        $role = $user->role instanceof \BackedEnum ? $user->role->value : (string) $user->role;

        return $user->tenant_id === null
            || in_array($role, ['super_admin', 'platform_admin'], true);
    }

    public function submitFarmerRating(
        Farmer $farmer,
        MarketplaceOrder $order,
        int $score,
        ?string $comment = null
    ): Rating {
        if ($order->farmer_id !== $farmer->id) {
            throw new DomainException('Only the owning farmer may rate this order.');
        }

        if ($order->status !== 'completed') {
            throw new DomainException('Only completed orders can be rated.');
        }

        if ($score < 1 || $score > 5) {
            throw new DomainException('Rating score must be between 1 and 5.');
        }

        return DB::transaction(function () use ($farmer, $order, $score, $comment): Rating {
            $exists = Rating::query()
                ->where('order_id', '=', $order->id)
                ->where('rater_type', '=', 'farmer')
                ->exists();

            if ($exists) {
                throw new DomainException('Farmer has already rated this order.');
            }

            $rating = Rating::query()->create([
                'order_id' => $order->id,
                'rater_type' => 'farmer',
                'rater_id' => $farmer->id,
                'ratee_type' => 'retailer',
                'ratee_id' => $order->organisation_id,
                'score' => $score,
                'comment' => $comment,
            ]);

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'actor_type' => 'farmer',
                'actor_id' => $farmer->id,
                'event_type' => 'farmer_rated_seller',
                'from_status' => $order->status,
                'to_status' => $order->status,
                'metadata_json' => ['rating_id' => $rating->id, 'score' => $score],
                'created_at' => now(),
            ]);

            $request = MarketplaceRequest::withoutGlobalScopes()->find($order->request_id);
            if ($request !== null && $request->status === MarketplaceRequestStatus::COMPLETED) {
                $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::RATED, 'farmer_rated_order', [
                    'rating_id' => $rating->id,
                    'score' => $score,
                ]);
            }

            return $rating;
        });
    }

    public function submitRetailerRating(
        User $user,
        MarketplaceOrder $order,
        int $score,
        ?string $comment = null
    ): Rating {
        if ($user->tenant_id === null || $order->organisation_id !== $user->tenant_id) {
            throw new DomainException('Only the fulfilling retailer may rate this farmer.');
        }

        if ($order->status !== 'completed') {
            throw new DomainException('Only completed orders can be rated.');
        }

        if ($score < 1 || $score > 5) {
            throw new DomainException('Rating score must be between 1 and 5.');
        }

        return DB::transaction(function () use ($user, $order, $score, $comment): Rating {
            $exists = Rating::query()
                ->where('order_id', '=', $order->id)
                ->where('rater_type', '=', 'retailer')
                ->exists();

            if ($exists) {
                throw new DomainException('Retailer has already rated this order.');
            }

            $rating = Rating::query()->create([
                'order_id' => $order->id,
                'rater_type' => 'retailer',
                'rater_id' => $order->organisation_id,
                'ratee_type' => 'farmer',
                'ratee_id' => $order->farmer_id,
                'score' => $score,
                'comment' => $comment,
            ]);

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'actor_type' => 'retailer',
                'actor_id' => $user->id,
                'event_type' => 'retailer_rated_farmer',
                'from_status' => $order->status,
                'to_status' => $order->status,
                'metadata_json' => ['rating_id' => $rating->id, 'score' => $score],
                'created_at' => now(),
            ]);

            return $rating;
        });
    }

    /**
     * @param array{
     *   outlet_id?: ?string,
     *   business_registration_number?: ?string,
     *   verified_address?: ?string,
     *   id_document_path?: ?string,
     *   outlet_photo_path?: ?string
     * } $data
     */
    public function submitSellerVerification(User $user, array $data): SellerVerification
    {
        if ($user->tenant_id === null) {
            throw new DomainException('User must belong to an organisation to submit seller verification.');
        }

        return SellerVerification::query()->create([
            'organisation_id' => $user->tenant_id,
            'outlet_id' => $data['outlet_id'] ?? null,
            'status' => 'pending',
            'business_registration_number' => $data['business_registration_number'] ?? null,
            'verified_address' => $data['verified_address'] ?? null,
            'id_document_path' => $data['id_document_path'] ?? null,
            'outlet_photo_path' => $data['outlet_photo_path'] ?? null,
        ]);
    }

    public function reviewSellerVerification(
        User $admin,
        SellerVerification $verification,
        string $decision,
        ?string $reason = null
    ): SellerVerification {
        if (!$this->isPlatformAdmin($admin)) {
            throw new DomainException('Only platform admins may review seller verifications.');
        }

        if (!in_array($decision, ['verified', 'suspended', 'pending'], true)) {
            throw new DomainException('Invalid seller verification decision: ' . $decision);
        }

        return DB::transaction(function () use ($admin, $verification, $decision, $reason): SellerVerification {
            $oldStatus = $verification->status;

            $verification->update([
                'status' => $decision,
                'reviewed_by_user_id' => $admin->id,
                'reviewed_at' => now(),
                'rejection_or_suspension_reason' => $reason,
            ]);

            AuditLog::query()->create([
                'tenant_id' => $verification->organisation_id,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'event_type' => 'seller_verification_reviewed',
                'entity_type' => SellerVerification::class,
                'entity_id' => $verification->id,
                'payload_json' => [
                    'from_status' => $oldStatus,
                    'to_status' => $decision,
                    'reason' => $reason,
                ],
                'created_at' => now(),
            ]);

            return $verification->fresh();
        });
    }

    public function openDispute(
        MarketplaceOrder $order,
        string $actorType,
        string $actorId,
        string $reason
    ): MarketplaceOrder {
        if (in_array($order->status, ['disputed', 'cancelled'], true)) {
            throw new DomainException('Order cannot be disputed in status ' . $order->status);
        }

        return DB::transaction(function () use ($order, $actorType, $actorId, $reason): MarketplaceOrder {
            /** @var MarketplaceOrder $locked */
            $locked = MarketplaceOrder::withoutGlobalScopes()
                ->where('id', '=', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromStatus = $locked->status;
            $locked->update(['status' => 'disputed']);

            OrderEvent::query()->create([
                'order_id' => $locked->id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event_type' => 'dispute_opened',
                'from_status' => $fromStatus,
                'to_status' => 'disputed',
                'metadata_json' => ['reason' => $reason],
                'created_at' => now(),
            ]);

            $request = MarketplaceRequest::withoutGlobalScopes()->find($locked->request_id);
            if ($request !== null) {
                if ($request->status === MarketplaceRequestStatus::OFFER_ACCEPTED) {
                    $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::FULFILMENT, 'dispute_in_fulfilment', [
                        'order_id' => $locked->id,
                    ]);
                    $request = $request->fresh();
                }

                if ($request->status === MarketplaceRequestStatus::FULFILMENT) {
                    $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::DISPUTED, 'dispute_opened', [
                        'order_id' => $locked->id,
                        'reason' => $reason,
                    ]);
                }
            }

            AuditLog::query()->create([
                'tenant_id' => $locked->organisation_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event_type' => 'marketplace_dispute_opened',
                'entity_type' => MarketplaceOrder::class,
                'entity_id' => $locked->id,
                'payload_json' => ['reason' => $reason],
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function resolveDispute(
        User $admin,
        MarketplaceOrder $order,
        string $resolution,
        string $notes,
        bool $suspendSeller = false
    ): MarketplaceOrder {
        if (!$this->isPlatformAdmin($admin)) {
            throw new DomainException('Only platform admins may resolve disputes.');
        }

        if ($order->status !== 'disputed') {
            throw new DomainException('Only disputed orders can be resolved.');
        }

        if (!in_array($resolution, ['complete', 'cancel'], true)) {
            throw new DomainException('Resolution must be complete or cancel.');
        }

        return DB::transaction(function () use ($admin, $order, $resolution, $notes, $suspendSeller): MarketplaceOrder {
            /** @var MarketplaceOrder $locked */
            $locked = MarketplaceOrder::withoutGlobalScopes()
                ->where('id', '=', $order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $targetOrderStatus = $resolution === 'complete' ? 'completed' : 'cancelled';
            $update = ['status' => $targetOrderStatus];
            if ($resolution === 'complete') {
                $update['completed_at'] = now();
            } elseif ($locked->payment_status === 'paid') {
                $update['payment_status'] = 'refunded';
            }

            $locked->update($update);

            OrderEvent::query()->create([
                'order_id' => $locked->id,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'event_type' => 'dispute_resolved',
                'from_status' => 'disputed',
                'to_status' => $targetOrderStatus,
                'metadata_json' => [
                    'resolution' => $resolution,
                    'notes' => $notes,
                    'suspend_seller' => $suspendSeller,
                ],
                'created_at' => now(),
            ]);

            $request = MarketplaceRequest::withoutGlobalScopes()->find($locked->request_id);
            if ($request !== null && $request->status === MarketplaceRequestStatus::DISPUTED) {
                $targetReqStatus = $resolution === 'complete'
                    ? MarketplaceRequestStatus::COMPLETED
                    : MarketplaceRequestStatus::CANCELLED;

                $this->stateMachine->transitionTo($request, $targetReqStatus, 'dispute_resolved_' . $resolution, [
                    'order_id' => $locked->id,
                    'admin_id' => $admin->id,
                ]);
            }

            if ($suspendSeller) {
                SellerVerification::query()->updateOrCreate(
                    ['organisation_id' => $locked->organisation_id, 'outlet_id' => null],
                    [
                        'status' => 'suspended',
                        'reviewed_by_user_id' => $admin->id,
                        'reviewed_at' => now(),
                        'rejection_or_suspension_reason' => $notes,
                    ]
                );
            }

            AuditLog::query()->create([
                'tenant_id' => $locked->organisation_id,
                'actor_type' => 'admin',
                'actor_id' => $admin->id,
                'event_type' => 'marketplace_dispute_resolved',
                'entity_type' => MarketplaceOrder::class,
                'entity_id' => $locked->id,
                'payload_json' => [
                    'resolution' => $resolution,
                    'notes' => $notes,
                    'suspend_seller' => $suspendSeller,
                ],
                'created_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
