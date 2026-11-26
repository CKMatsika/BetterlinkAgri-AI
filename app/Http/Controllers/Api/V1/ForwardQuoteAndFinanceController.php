<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrganisationType;
use App\Models\ConsentEvent;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\FinanceApplication;
use App\Models\ForwardQuote;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\ForwardQuoteAndFinanceService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ForwardQuoteAndFinanceController
{
    public function __construct(
        private readonly ForwardQuoteAndFinanceService $service
    ) {
    }

    public function requestForwardQuote(Request $request): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'offtaker_organisation_id' => ['required', 'uuid'],
            'commodity_code' => ['required', 'string', 'max:60'],
            'commodity_name' => ['required', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:40'],
            'quantity_scaled' => ['required', 'integer', 'min:1'],
            'unit' => ['nullable', 'string', 'max:30'],
            'currency' => ['nullable', 'in:USD,ZIG'],
            'delivery_window_start' => ['required', 'date'],
            'delivery_window_end' => ['required', 'date', 'after_or_equal:delivery_window_start'],
            'farm_plan_id' => ['nullable', 'uuid'],
            'plan_requirement_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $offtaker = Tenant::query()->find($validated['offtaker_organisation_id']);
        if ($offtaker === null) {
            return response()->json(['error' => 'Offtaker organisation not found.'], 404);
        }

        try {
            $quote = $this->service->requestForwardQuote($farmer, $offtaker, $validated);
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'forward_quote' => [
                'id' => $quote->id,
                'status' => $quote->status,
                'commodity_code' => $quote->commodity_code,
                'quantity_scaled' => $quote->quantity_scaled,
                'plan_requirement_id' => $quote->plan_requirement_id,
            ],
        ], 201);
    }

    public function acceptForwardQuote(Request $request, string $quoteId): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'grant_contact_consent' => ['nullable', 'boolean'],
        ]);

        $quote = ForwardQuote::query()->find($quoteId);
        if ($quote === null) {
            return response()->json(['error' => 'Forward quote not found.'], 404);
        }

        try {
            $accepted = $this->service->acceptForwardQuote(
                $farmer,
                $quote,
                (bool) ($validated['grant_contact_consent'] ?? false)
            );
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'forward_quote' => [
                'id' => $accepted->id,
                'status' => $accepted->status,
                'total_value_minor' => $accepted->total_value_minor,
                'farmer_contact_revealed' => $accepted->farmer_contact_revealed_at !== null,
            ],
        ]);
    }

    public function offtakerIndex(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $org = $user?->tenant;
        if ($user === null || $org === null || $org->organisation_type !== OrganisationType::OFFTAKER) {
            return response()->json(['error' => 'Forbidden: offtaker organisation required.'], 403);
        }

        $quotes = ForwardQuote::query()
            ->with(['farmer'])
            ->where('offtaker_organisation_id', '=', $org->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json([
            'forward_quotes' => $quotes->map(static fn (ForwardQuote $q): array => [
                'id' => $q->id,
                'commodity_code' => $q->commodity_code,
                'commodity_name' => $q->commodity_name,
                'quantity_scaled' => $q->quantity_scaled,
                'unit' => $q->unit,
                'quoted_unit_price_minor' => $q->quoted_unit_price_minor,
                'total_value_minor' => $q->total_value_minor,
                'status' => $q->status,
                'farmer_phone' => $q->farmer_contact_revealed_at !== null ? $q->farmer?->phone_encrypted : null,
            ])->values()->all(),
        ]);
    }

    public function submitOfftakerQuote(Request $request, string $quoteId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant === null) {
            return response()->json(['error' => 'Authenticated organisation user required.'], 401);
        }

        $validated = $request->validate([
            'quoted_unit_price_minor' => ['required', 'integer', 'min:1'],
            'valid_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $quote = ForwardQuote::query()->find($quoteId);
        if ($quote === null) {
            return response()->json(['error' => 'Forward quote not found.'], 404);
        }

        try {
            $priced = $this->service->submitOfftakerQuote(
                $user,
                $quote,
                (int) $validated['quoted_unit_price_minor'],
                (int) ($validated['valid_hours'] ?? 72),
                $validated['notes'] ?? null
            );
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'forward_quote' => [
                'id' => $priced->id,
                'status' => $priced->status,
                'quoted_unit_price_minor' => $priced->quoted_unit_price_minor,
                'total_value_minor' => $priced->total_value_minor,
            ],
        ]);
    }

    public function submitFinanceApplication(Request $request): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $validated = $request->validate([
            'bank_organisation_id' => ['required', 'uuid'],
            'farm_plan_id' => ['required', 'uuid'],
            'forward_quote_id' => ['nullable', 'uuid'],
            'requested_amount_minor' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'in:USD,ZIG'],
            'purpose' => ['nullable', 'in:input_finance,working_capital,equipment'],
            'grant_bank_sharing_consent' => ['nullable', 'boolean'],
        ]);

        $bank = Tenant::query()->find($validated['bank_organisation_id']);
        if ($bank === null) {
            return response()->json(['error' => 'Bank organisation not found.'], 404);
        }

        $plan = FarmPlan::query()->find($validated['farm_plan_id']);
        if ($plan === null) {
            return response()->json(['error' => 'Farm plan not found.'], 404);
        }

        try {
            $app = $this->service->submitFinanceApplication($farmer, $bank, $plan, $validated);
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'application' => [
                'id' => $app->id,
                'status' => $app->status,
                'requested_amount_minor' => $app->requested_amount_minor,
                'consent_event_id' => $app->consent_event_id,
                'forward_quote_id' => $app->forward_quote_id,
                'plan_snapshot_json' => $app->plan_snapshot_json,
            ],
        ], 201);
    }

    public function revokeBankConsent(Request $request, string $bankTenantId): JsonResponse
    {
        $farmer = $this->resolveAuthenticatedFarmer($request);
        if ($farmer === null) {
            return response()->json(['error' => 'Authenticated farmer required.'], 401);
        }

        $event = $this->service->revokeBankConsent($farmer, $bankTenantId);

        return response()->json([
            'consent_event' => [
                'id' => $event->id,
                'scope' => $event->scope,
                'action' => $event->action,
            ],
        ]);
    }

    public function bankIndex(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $org = $user?->tenant;
        if ($user === null || $org === null || $org->organisation_type !== OrganisationType::BANK) {
            return response()->json(['error' => 'Forbidden: bank organisation required.'], 403);
        }

        $apps = FinanceApplication::query()
            ->where('bank_organisation_id', '=', $org->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->filter(static fn (FinanceApplication $a): bool => ConsentEvent::hasActiveConsent($org->id, $a->farmer_id, 'bank_plan_sharing'))
            ->values();

        return response()->json([
            'applications' => $apps->map(static fn (FinanceApplication $a): array => [
                'id' => $a->id,
                'status' => $a->status,
                'requested_amount_minor' => $a->requested_amount_minor,
                'approved_amount_minor' => $a->approved_amount_minor,
                'purpose' => $a->purpose,
                'plan_snapshot_json' => $a->plan_snapshot_json,
            ])->all(),
        ]);
    }

    public function reviewFinanceApplication(Request $request, string $applicationId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant === null) {
            return response()->json(['error' => 'Authenticated bank user required.'], 401);
        }

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,declined,under_review'],
            'approved_amount_minor' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $app = FinanceApplication::query()->find($applicationId);
        if ($app === null) {
            return response()->json(['error' => 'Finance application not found.'], 404);
        }

        try {
            $reviewed = $this->service->reviewFinanceApplication(
                $user,
                $app,
                (string) $validated['decision'],
                isset($validated['approved_amount_minor']) ? (int) $validated['approved_amount_minor'] : null,
                $validated['notes'] ?? null
            );
        } catch (DomainException $e) {
            $status = str_starts_with($e->getMessage(), 'Forbidden:') ? 403 : 422;

            return response()->json(['error' => $e->getMessage()], $status);
        }

        return response()->json([
            'application' => [
                'id' => $reviewed->id,
                'status' => $reviewed->status,
                'approved_amount_minor' => $reviewed->approved_amount_minor,
                'decision_notes' => $reviewed->decision_notes,
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
