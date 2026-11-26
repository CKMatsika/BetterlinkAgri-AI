<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\MarketplaceRequest;
use App\Models\PlanRequirement;
use App\Models\Product;
use App\Models\RequestItem;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Bridges AI Diagnosis advisories, Farm Plan requirement schedules, and Farmer-confirmed Substitutes
 * into live MarketplaceRequests.
 */
final class MarketplaceFromDiagnosisAndPlanService
{
    public function __construct(
        private readonly MarketplaceBroadcastService $broadcastService,
        private readonly SubstituteResolverService $substituteResolver
    ) {
    }

    /**
     * Create and broadcast a MarketplaceRequest from an AI Diagnosis conversation.
     *
     * @param list<array{product_id?: ?string, category?: ?string, item_name: string, quantity_scaled: int, unit: string}> $items
     */
    public function createFromDiagnosis(
        Conversation $conversation,
        ?Diagnosis $diagnosis,
        array $items,
        float $lat,
        float $lng,
        string $currency = 'USD'
    ): MarketplaceRequest {
        $farmer = $conversation->farmer;
        $ward = $farmer?->ward;

        $marketRequest = DB::transaction(function () use ($conversation, $diagnosis, $items, $lat, $lng, $currency, $farmer, $ward): MarketplaceRequest {
            $req = MarketplaceRequest::withoutGlobalScopes()->create([
                'farmer_id' => $conversation->farmer_id,
                'conversation_id' => $conversation->id,
                'diagnosis_id' => $diagnosis?->id,
                'origin_organisation_id' => $conversation->tenant_id,
                'request_type' => 'stock',
                'status' => MarketplaceRequestStatus::DRAFT,
                'source' => 'ai_conversation',
                'province_id' => $ward?->district?->province_id,
                'district_id' => $ward?->district_id,
                'ward_id' => $ward?->id,
                'approx_latitude' => $lat,
                'approx_longitude' => $lng,
                'farmer_phone_encrypted' => $farmer?->phone_encrypted,
                'delivery_preference' => 'either',
                'currency' => CurrencyCode::tryFrom($currency) ?? CurrencyCode::USD,
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE requests SET approx_location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $req->id]
                );
            }

            foreach ($items as $item) {
                RequestItem::query()->create([
                    'request_id' => $req->id,
                    'product_id' => $item['product_id'] ?? null,
                    'category' => $item['category'] ?? null,
                    'item_name' => $item['item_name'],
                    'quantity_scaled' => (int) $item['quantity_scaled'],
                    'unit' => $item['unit'],
                ]);
            }

            return $req;
        });

        $this->broadcastService->broadcastRing($marketRequest->fresh(), 1);

        return $marketRequest->fresh()->load('items');
    }

    /**
     * Activate a scheduled FarmPlan requirement into a live MarketplaceRequest,
     * linking plan_requirements.marketplace_request_id.
     */
    public function activatePlanRequirement(
        PlanRequirement $requirement,
        float $lat,
        float $lng
    ): MarketplaceRequest {
        if ($requirement->marketplace_request_id !== null) {
            return MarketplaceRequest::withoutGlobalScopes()
                ->with('items')
                ->findOrFail($requirement->marketplace_request_id);
        }

        $plan = $requirement->farmPlan()->with(['farmer.ward.district'])->firstOrFail();
        $farmer = $plan->farmer;
        $ward = $farmer?->ward;

        $marketRequest = DB::transaction(function () use ($requirement, $plan, $farmer, $ward, $lat, $lng): MarketplaceRequest {
            $req = MarketplaceRequest::withoutGlobalScopes()->create([
                'farmer_id' => $plan->farmer_id,
                'origin_organisation_id' => $plan->tenant_id,
                'request_type' => $requirement->requirement_type === 'livestock_booking' ? 'booking' : 'stock',
                'status' => MarketplaceRequestStatus::DRAFT,
                'source' => 'plan_schedule',
                'province_id' => $ward?->district?->province_id,
                'district_id' => $ward?->district_id,
                'ward_id' => $ward?->id,
                'approx_latitude' => $lat,
                'approx_longitude' => $lng,
                'farmer_phone_encrypted' => $farmer?->phone_encrypted,
                'needed_by_date' => $requirement->needed_by_date,
                'delivery_preference' => 'either',
                'currency' => $plan->currency ?? CurrencyCode::USD,
                'metadata_json' => [
                    'farm_plan_id' => $plan->id,
                    'plan_requirement_id' => $requirement->id,
                ],
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE requests SET approx_location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $req->id]
                );
            }

            RequestItem::query()->create([
                'request_id' => $req->id,
                'category' => $requirement->requirement_type,
                'item_name' => $requirement->item_name,
                'quantity_scaled' => (int) $requirement->quantity_scaled,
                'unit' => $requirement->unit,
            ]);

            $requirement->update([
                'status' => 'activated',
                'farmer_confirmed_activation_at' => $requirement->farmer_confirmed_activation_at ?? now(),
                'marketplace_request_id' => $req->id,
            ]);

            return $req;
        });

        $this->broadcastService->broadcastRing($marketRequest->fresh(), 1);

        return $marketRequest->fresh()->load('items');
    }

    /**
     * Farmer explicitly confirms a safe registered substitute product -> creates a follow-up request & broadcasts Ring 1.
     */
    public function confirmSubstitute(
        Farmer $farmer,
        MarketplaceRequest $originalRequest,
        Product $substituteProduct,
        int $quantityScaled,
        string $unit
    ): MarketplaceRequest {
        if ($originalRequest->farmer_id !== $farmer->id) {
            throw new DomainException('Only the owning farmer may confirm a substitute.');
        }

        if (!$this->substituteResolver->isSafeRegisteredProduct($substituteProduct)) {
            throw new DomainException('Selected substitute fails safety or registration guardrails.');
        }

        $lat = (float) ($originalRequest->approx_latitude ?? -17.8292);
        $lng = (float) ($originalRequest->approx_longitude ?? 31.0522);

        $subRequest = DB::transaction(function () use ($farmer, $originalRequest, $substituteProduct, $quantityScaled, $unit, $lat, $lng): MarketplaceRequest {
            $req = MarketplaceRequest::withoutGlobalScopes()->create([
                'farmer_id' => $farmer->id,
                'conversation_id' => $originalRequest->conversation_id,
                'diagnosis_id' => $originalRequest->diagnosis_id,
                'origin_organisation_id' => $originalRequest->origin_organisation_id,
                'request_type' => 'stock',
                'status' => MarketplaceRequestStatus::DRAFT,
                'source' => $originalRequest->source,
                'province_id' => $originalRequest->province_id,
                'district_id' => $originalRequest->district_id,
                'ward_id' => $originalRequest->ward_id,
                'approx_latitude' => $lat,
                'approx_longitude' => $lng,
                'exact_location_encrypted' => $originalRequest->exact_location_encrypted,
                'farmer_phone_encrypted' => $originalRequest->farmer_phone_encrypted,
                'delivery_preference' => $originalRequest->delivery_preference,
                'needed_by_date' => $originalRequest->needed_by_date,
                'currency' => $originalRequest->currency,
                'metadata_json' => [
                    'substituted_from_request_id' => $originalRequest->id,
                    'farmer_confirmed_substitute' => true,
                ],
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE requests SET approx_location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $req->id]
                );
            }

            RequestItem::query()->create([
                'request_id' => $req->id,
                'product_id' => $substituteProduct->id,
                'category' => $substituteProduct->category,
                'item_name' => $substituteProduct->name,
                'quantity_scaled' => $quantityScaled,
                'unit' => $unit,
                'notes' => 'Farmer-confirmed safe registered substitute',
            ]);

            return $req;
        });

        $this->broadcastService->broadcastRing($subRequest->fresh(), 1);

        return $subRequest->fresh()->load('items');
    }
}
