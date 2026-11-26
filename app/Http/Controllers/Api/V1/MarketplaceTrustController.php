<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\SellerVerification;
use App\Models\User;
use App\Services\Marketplace\MarketplaceTrustAndDisputeService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketplaceTrustController
{
    public function __construct(
        private readonly MarketplaceTrustAndDisputeService $trustService
    ) {
    }

    public function farmerRate(Request $request, string $orderId): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        if ($order->farmer_id !== $farmer->id) {
            return response()->json(['error' => 'Forbidden: order belongs to another farmer.'], 403);
        }

        try {
            $rating = $this->trustService->submitFarmerRating(
                $farmer,
                $order,
                (int) $validated['score'],
                $validated['comment'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'rating' => [
                'id' => $rating->id,
                'order_id' => $rating->order_id,
                'rater_type' => $rating->rater_type,
                'ratee_type' => $rating->ratee_type,
                'score' => $rating->score,
                'comment' => $rating->comment,
            ],
        ], 201);
    }

    public function retailerRate(Request $request, string $orderId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return response()->json(['error' => 'Authenticated organisation user required.'], 401);
        }

        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        if ($order->organisation_id !== $user->tenant_id) {
            return response()->json(['error' => 'Forbidden: order belongs to another organisation.'], 403);
        }

        try {
            $rating = $this->trustService->submitRetailerRating(
                $user,
                $order,
                (int) $validated['score'],
                $validated['comment'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'rating' => [
                'id' => $rating->id,
                'order_id' => $rating->order_id,
                'rater_type' => $rating->rater_type,
                'ratee_type' => $rating->ratee_type,
                'score' => $rating->score,
                'comment' => $rating->comment,
            ],
        ], 201);
    }

    public function submitVerification(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return response()->json(['error' => 'Authenticated organisation user required.'], 401);
        }

        $validated = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'business_registration_number' => ['required', 'string', 'max:100'],
            'verified_address' => ['required', 'string', 'max:500'],
            'id_document_path' => ['nullable', 'string', 'max:255'],
            'outlet_photo_path' => ['nullable', 'string', 'max:255'],
        ]);

        $verification = $this->trustService->submitSellerVerification($user, $validated);

        return response()->json([
            'verification' => [
                'id' => $verification->id,
                'organisation_id' => $verification->organisation_id,
                'status' => $verification->status,
                'business_registration_number' => $verification->business_registration_number,
            ],
        ], 201);
    }

    public function reviewVerification(Request $request, string $verificationId): JsonResponse
    {
        /** @var User|null $admin */
        $admin = $request->user();
        if ($admin === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        if (!$this->trustService->isPlatformAdmin($admin)) {
            return response()->json(['error' => 'Forbidden: platform admin role required.'], 403);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:verified,suspended,pending'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $verification = SellerVerification::query()->find($verificationId);
        if ($verification === null) {
            return response()->json(['error' => 'Verification record not found.'], 404);
        }

        $updated = $this->trustService->reviewSellerVerification(
            $admin,
            $verification,
            (string) $validated['decision'],
            $validated['reason'] ?? null
        );

        return response()->json([
            'verification' => [
                'id' => $updated->id,
                'organisation_id' => $updated->organisation_id,
                'status' => $updated->status,
                'reviewed_at' => $updated->reviewed_at?->toIso8601String(),
                'rejection_or_suspension_reason' => $updated->rejection_or_suspension_reason,
            ],
        ]);
    }

    public function farmerDispute(Request $request, string $orderId): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        if ($order->farmer_id !== $farmer->id) {
            return response()->json(['error' => 'Forbidden: order belongs to another farmer.'], 403);
        }

        try {
            $disputed = $this->trustService->openDispute($order, 'farmer', $farmer->id, (string) $validated['reason']);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => [
                'id' => $disputed->id,
                'status' => $disputed->status,
            ],
        ]);
    }

    public function retailerDispute(Request $request, string $orderId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return response()->json(['error' => 'Authenticated organisation user required.'], 401);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        if ($order->organisation_id !== $user->tenant_id) {
            return response()->json(['error' => 'Forbidden: order belongs to another organisation.'], 403);
        }

        try {
            $disputed = $this->trustService->openDispute($order, 'retailer', $user->id, (string) $validated['reason']);
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => [
                'id' => $disputed->id,
                'status' => $disputed->status,
            ],
        ]);
    }

    public function resolveDispute(Request $request, string $orderId): JsonResponse
    {
        /** @var User|null $admin */
        $admin = $request->user();
        if ($admin === null) {
            return response()->json(['error' => 'Authentication required.'], 401);
        }

        if (!$this->trustService->isPlatformAdmin($admin)) {
            return response()->json(['error' => 'Forbidden: platform admin role required.'], 403);
        }

        $validated = $request->validate([
            'resolution' => ['required', 'in:complete,cancel'],
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
            'suspend_seller' => ['nullable', 'boolean'],
        ]);

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        try {
            $resolved = $this->trustService->resolveDispute(
                $admin,
                $order,
                (string) $validated['resolution'],
                (string) $validated['notes'],
                (bool) ($validated['suspend_seller'] ?? false)
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'order' => [
                'id' => $resolved->id,
                'status' => $resolved->status,
                'payment_status' => $resolved->payment_status,
            ],
        ]);
    }

    private function resolveAuthenticatedFarmer(Request $request): ?Farmer
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        if ($farmerId === '') {
            return null;
        }

        return Farmer::query()->find($farmerId);
    }
}
