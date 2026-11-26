<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Farmer;
use App\Models\ServiceBooking;
use App\Models\ServiceProvider;
use App\Models\User;
use App\Services\Marketplace\ServiceBookingService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ServiceBookingController
{
    public function __construct(
        private readonly ServiceBookingService $bookingService
    ) {
    }

    public function nearbyProviders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_type' => ['required', 'in:tillage,spraying,soil_testing,transport,veterinary,agronomy_visit'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'max_radius_km' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $providers = $this->bookingService->findNearbyProviders(
            (string) $validated['service_type'],
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            (int) ($validated['max_radius_km'] ?? 100)
        );

        return response()->json([
            'providers' => $providers->map(static fn (ServiceProvider $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'service_type' => $p->service_type,
                'coverage_radius_km' => $p->coverage_radius_km,
                'unit_rate_minor' => $p->unit_rate_minor,
                'rate_unit' => $p->rate_unit,
                'currency' => $p->currency instanceof \BackedEnum ? $p->currency->value : (string) $p->currency,
                'is_verified' => $p->is_verified,
            ])->values()->all(),
        ]);
    }

    public function farmerIndex(Request $request): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $bookings = ServiceBooking::query()
            ->with(['serviceProvider'])
            ->where('farmer_id', '=', $farmer->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'bookings' => $bookings->map(static fn (ServiceBooking $b): array => [
                'id' => $b->id,
                'service_type' => $b->service_type,
                'status' => $b->status,
                'scheduled_date' => $b->scheduled_date?->toDateString(),
                'quantity_scaled' => $b->quantity_scaled,
                'unit' => $b->unit,
                'quoted_amount_minor' => $b->quoted_amount_minor,
                'currency' => $b->currency instanceof \BackedEnum ? $b->currency->value : (string) $b->currency,
                'plan_requirement_id' => $b->plan_requirement_id,
                'provider_name' => $b->serviceProvider?->name,
            ])->values()->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'service_provider_id' => ['required', 'uuid'],
            'scheduled_date' => ['required', 'date'],
            'quantity_scaled' => ['required', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:30'],
            'plan_requirement_id' => ['nullable', 'uuid'],
            'approx_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'approx_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'grant_contact_consent' => ['nullable', 'boolean'],
        ]);

        $provider = ServiceProvider::query()->find($validated['service_provider_id']);
        if ($provider === null) {
            return response()->json(['error' => 'Service provider not found.'], 404);
        }

        try {
            $booking = $this->bookingService->createBooking($farmer, $provider, $validated);
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'service_provider_id' => $booking->service_provider_id,
                'service_type' => $booking->service_type,
                'status' => $booking->status,
                'scheduled_date' => $booking->scheduled_date?->toDateString(),
                'quantity_scaled' => $booking->quantity_scaled,
                'quoted_amount_minor' => $booking->quoted_amount_minor,
                'plan_requirement_id' => $booking->plan_requirement_id,
                'farmer_contact_revealed' => $booking->farmer_contact_revealed_at !== null,
            ],
        ], 201);
    }

    public function farmerCancel(Request $request, string $bookingId): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $booking = ServiceBooking::query()->find($bookingId);
        if ($booking === null) {
            return response()->json(['error' => 'Booking not found.'], 404);
        }

        if ($booking->farmer_id !== $farmer->id) {
            return response()->json(['error' => 'Forbidden: booking belongs to another farmer.'], 403);
        }

        try {
            $updated = $this->bookingService->transitionBooking($booking, 'cancelled', 'farmer', $farmer->id, 'Cancelled by farmer');
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'booking' => [
                'id' => $updated->id,
                'status' => $updated->status,
            ],
        ]);
    }

    public function providerIndex(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return response()->json(['error' => 'Authenticated provider user required.'], 401);
        }

        $bookings = ServiceBooking::query()
            ->with(['farmer'])
            ->where('organisation_id', '=', $user->tenant_id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'bookings' => $bookings->map(static fn (ServiceBooking $b): array => [
                'id' => $b->id,
                'service_type' => $b->service_type,
                'status' => $b->status,
                'scheduled_date' => $b->scheduled_date?->toDateString(),
                'quantity_scaled' => $b->quantity_scaled,
                'quoted_amount_minor' => $b->quoted_amount_minor,
                // Reveal farmer phone ONLY when explicit contact-reveal consent was granted
                'farmer_phone' => $b->farmer_contact_revealed_at !== null ? $b->farmer?->phone_encrypted : null,
            ])->values()->all(),
        ]);
    }

    public function providerUpdateStatus(Request $request, string $bookingId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            return response()->json(['error' => 'Authenticated provider user required.'], 401);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:confirmed,in_progress,completed,cancelled,disputed'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking = ServiceBooking::query()->find($bookingId);
        if ($booking === null) {
            return response()->json(['error' => 'Booking not found.'], 404);
        }

        if ($booking->organisation_id !== $user->tenant_id) {
            return response()->json(['error' => 'Forbidden: booking belongs to another organisation.'], 403);
        }

        try {
            $updated = $this->bookingService->transitionBooking(
                $booking,
                (string) $validated['status'],
                'provider',
                $user->id,
                $validated['notes'] ?? null
            );
        } catch (DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'booking' => [
                'id' => $updated->id,
                'status' => $updated->status,
                'completed_at' => $updated->completed_at?->toIso8601String(),
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
