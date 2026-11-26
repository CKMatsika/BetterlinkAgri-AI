<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\CurrencyCode;
use App\Enums\OrganisationType;
use App\Models\AuditLog;
use App\Models\ConsentEvent;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\FinanceApplication;
use App\Models\ForwardQuote;
use App\Models\PlanRequirement;
use App\Models\Tenant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic M5 Forward Quotes (Offtakers) & Consent-Gated Agri-Finance (Banks) Service.
 * Strictly enforces CDPA explicit farmer consent (bank_plan_sharing & offtaker_contact_reveal).
 */
final class ForwardQuoteAndFinanceService
{
    /**
     * @param array{
     *   commodity_code: string,
     *   commodity_name: string,
     *   grade?: ?string,
     *   quantity_scaled: int,
     *   unit?: string,
     *   currency?: string,
     *   delivery_window_start: string,
     *   delivery_window_end: string,
     *   farm_plan_id?: ?string,
     *   plan_requirement_id?: ?string,
     *   notes?: ?string
     * } $data
     */
    public function requestForwardQuote(Farmer $farmer, Tenant $offtaker, array $data): ForwardQuote
    {
        if ($offtaker->organisation_type !== OrganisationType::OFFTAKER) {
            throw new DomainException('Target organisation must be a registered offtaker.');
        }

        return DB::transaction(function () use ($farmer, $offtaker, $data): ForwardQuote {
            $planReq = null;
            $farmPlanId = $data['farm_plan_id'] ?? null;

            if (!empty($data['plan_requirement_id'])) {
                $planReq = PlanRequirement::query()->with(['farmPlan'])->findOrFail((string) $data['plan_requirement_id']);
                if ($planReq->farmPlan === null || $planReq->farmPlan->farmer_id !== $farmer->id) {
                    throw new DomainException('Forbidden: plan requirement belongs to another farmer.');
                }
                $farmPlanId = $planReq->farm_plan_id;
            } elseif ($farmPlanId !== null) {
                $plan = FarmPlan::query()->findOrFail($farmPlanId);
                if ($plan->farmer_id !== $farmer->id) {
                    throw new DomainException('Forbidden: farm plan belongs to another farmer.');
                }
            }

            $quote = ForwardQuote::query()->create([
                'farmer_id' => $farmer->id,
                'offtaker_organisation_id' => $offtaker->id,
                'farm_plan_id' => $farmPlanId,
                'plan_requirement_id' => $planReq?->id,
                'commodity_code' => (string) $data['commodity_code'],
                'commodity_name' => (string) $data['commodity_name'],
                'grade' => $data['grade'] ?? null,
                'quantity_scaled' => max(1, (int) $data['quantity_scaled']),
                'unit' => (string) ($data['unit'] ?? 'tonnes'),
                'currency' => CurrencyCode::tryFrom((string) ($data['currency'] ?? 'USD')) ?? CurrencyCode::USD,
                'delivery_window_start' => $data['delivery_window_start'],
                'delivery_window_end' => $data['delivery_window_end'],
                'status' => 'requested',
                'notes' => $data['notes'] ?? null,
            ]);

            if ($planReq !== null) {
                $planReq->update(['forward_quote_id' => $quote->id]);
            }

            AuditLog::query()->create([
                'tenant_id' => $offtaker->id,
                'actor_type' => 'farmer',
                'actor_id' => $farmer->id,
                'event_type' => 'forward_quote_requested',
                'entity_type' => ForwardQuote::class,
                'entity_id' => $quote->id,
                'payload_json' => [
                    'commodity_code' => $quote->commodity_code,
                    'quantity_scaled' => $quote->quantity_scaled,
                ],
                'created_at' => now(),
            ]);

            return $quote->fresh();
        });
    }

    public function submitOfftakerQuote(
        User $user,
        ForwardQuote $quote,
        int $unitPriceMinor,
        int $validHours = 72,
        ?string $notes = null
    ): ForwardQuote {
        $org = $user->tenant;
        if ($org === null || $org->organisation_type !== OrganisationType::OFFTAKER || $quote->offtaker_organisation_id !== $org->id) {
            throw new DomainException('Forbidden: only the target offtaker may quote this request.');
        }

        if ($quote->status !== 'requested') {
            throw new DomainException('Forward quote cannot be priced in status ' . $quote->status);
        }

        $totalValueMinor = (int) round(((int) $quote->quantity_scaled / 100.0) * $unitPriceMinor);

        $quote->update([
            'quoted_unit_price_minor' => $unitPriceMinor,
            'total_value_minor' => $totalValueMinor,
            'status' => 'quoted',
            'valid_until' => now()->addHours(max(1, $validHours)),
            'notes' => $notes ?? $quote->notes,
        ]);

        return $quote->fresh();
    }

    public function acceptForwardQuote(
        Farmer $farmer,
        ForwardQuote $quote,
        bool $grantContactConsent = false,
        string $channel = 'web'
    ): ForwardQuote {
        if ($quote->farmer_id !== $farmer->id) {
            throw new DomainException('Forbidden: forward quote belongs to another farmer.');
        }

        if ($quote->status !== 'quoted') {
            throw new DomainException('Only quoted forward offers can be accepted.');
        }

        return DB::transaction(function () use ($farmer, $quote, $grantContactConsent, $channel): ForwardQuote {
            if ($grantContactConsent) {
                ConsentEvent::record(
                    tenantId: $quote->offtaker_organisation_id,
                    farmerId: $farmer->id,
                    scope: 'offtaker_contact_reveal',
                    action: 'granted',
                    channel: $channel,
                    source: 'farmer_forward_quote_accept',
                    termsVersion: (string) config('betterlink.cdpa.current_policy_version', '2026.1')
                );
            }

            $hasConsent = ConsentEvent::hasActiveConsent($quote->offtaker_organisation_id, $farmer->id, 'offtaker_contact_reveal')
                || ConsentEvent::hasActiveConsent(null, $farmer->id, 'offtaker_contact_reveal');

            $quote->update([
                'status' => 'accepted',
                'farmer_contact_revealed_at' => $hasConsent ? now() : null,
            ]);

            if ($quote->plan_requirement_id !== null) {
                PlanRequirement::query()
                    ->where('id', '=', $quote->plan_requirement_id)
                    ->update(['status' => 'quoted']);
            }

            return $quote->fresh();
        });
    }

    /**
     * Submit a consent-gated agri-finance application to a bank.
     * Strictly requires explicit bank_plan_sharing consent.
     *
     * @param array{
     *   requested_amount_minor: int,
     *   currency?: string,
     *   purpose?: string,
     *   forward_quote_id?: ?string,
     *   grant_bank_sharing_consent?: bool,
     *   channel?: string
     * } $data
     */
    public function submitFinanceApplication(
        Farmer $farmer,
        Tenant $bank,
        FarmPlan $plan,
        array $data
    ): FinanceApplication {
        if ($bank->organisation_type !== OrganisationType::BANK) {
            throw new DomainException('Target organisation must be a registered bank.');
        }

        if ($plan->farmer_id !== $farmer->id) {
            throw new DomainException('Forbidden: farm plan belongs to another farmer.');
        }

        return DB::transaction(function () use ($farmer, $bank, $plan, $data): FinanceApplication {
            $grantConsent = (bool) ($data['grant_bank_sharing_consent'] ?? false);
            $consentEvent = null;

            if ($grantConsent) {
                $consentEvent = ConsentEvent::record(
                    tenantId: $bank->id,
                    farmerId: $farmer->id,
                    scope: 'bank_plan_sharing',
                    action: 'granted',
                    channel: (string) ($data['channel'] ?? 'web'),
                    source: 'farmer_finance_application',
                    termsVersion: (string) config('betterlink.cdpa.current_policy_version', '2026.1')
                );
            } elseif (ConsentEvent::hasActiveConsent($bank->id, $farmer->id, 'bank_plan_sharing')) {
                $consentEvent = ConsentEvent::query()
                    ->where('tenant_id', '=', $bank->id)
                    ->where('farmer_id', '=', $farmer->id)
                    ->where('scope', '=', 'bank_plan_sharing')
                    ->where('action', '=', 'granted')
                    ->latest('occurred_at')
                    ->first();
            }

            if ($consentEvent === null) {
                throw new DomainException('Explicit farmer consent (bank_plan_sharing) is required before sharing farm plan data with a bank.');
            }

            $forwardQuote = null;
            if (!empty($data['forward_quote_id'])) {
                $forwardQuote = ForwardQuote::query()->findOrFail((string) $data['forward_quote_id']);
                if ($forwardQuote->farmer_id !== $farmer->id) {
                    throw new DomainException('Forbidden: forward quote belongs to another farmer.');
                }
            }

            $snapshot = [
                'farm_plan_id' => $plan->id,
                'title' => $plan->title,
                'scale_value' => $plan->scale_value,
                'scale_unit' => $plan->scale_unit,
                'requirements_count' => $plan->requirements()->count(),
                'forward_quote_id' => $forwardQuote?->id,
                'forward_quote_value_minor' => $forwardQuote?->total_value_minor,
                'snapshot_captured_at' => now()->toIso8601String(),
            ];

            $app = FinanceApplication::query()->create([
                'farmer_id' => $farmer->id,
                'bank_organisation_id' => $bank->id,
                'farm_plan_id' => $plan->id,
                'forward_quote_id' => $forwardQuote?->id,
                'consent_event_id' => $consentEvent->id,
                'requested_amount_minor' => max(1, (int) $data['requested_amount_minor']),
                'currency' => CurrencyCode::tryFrom((string) ($data['currency'] ?? 'USD')) ?? CurrencyCode::USD,
                'purpose' => (string) ($data['purpose'] ?? 'input_finance'),
                'status' => 'submitted',
                'plan_snapshot_json' => $snapshot,
            ]);

            AuditLog::query()->create([
                'tenant_id' => $bank->id,
                'actor_type' => 'farmer',
                'actor_id' => $farmer->id,
                'event_type' => 'finance_application_submitted',
                'entity_type' => FinanceApplication::class,
                'entity_id' => $app->id,
                'payload_json' => [
                    'requested_amount_minor' => $app->requested_amount_minor,
                    'consent_event_id' => $consentEvent->id,
                ],
                'created_at' => now(),
            ]);

            return $app->fresh();
        });
    }

    public function reviewFinanceApplication(
        User $bankUser,
        FinanceApplication $app,
        string $decision,
        ?int $approvedAmountMinor = null,
        ?string $notes = null
    ): FinanceApplication {
        $org = $bankUser->tenant;
        if ($org === null || $org->organisation_type !== OrganisationType::BANK || $app->bank_organisation_id !== $org->id) {
            throw new DomainException('Forbidden: only the assigned bank may review this application.');
        }

        // Re-verify live CDPA consent before allowing bank review
        if (!ConsentEvent::hasActiveConsent($org->id, $app->farmer_id, 'bank_plan_sharing')) {
            throw new DomainException('Forbidden: farmer has revoked bank_plan_sharing consent.');
        }

        if (!in_array($decision, ['approved', 'declined', 'under_review'], true)) {
            throw new DomainException('Invalid finance review decision: ' . $decision);
        }

        return DB::transaction(function () use ($bankUser, $org, $app, $decision, $approvedAmountMinor, $notes): FinanceApplication {
            $app->update([
                'status' => $decision,
                'approved_amount_minor' => $decision === 'approved' ? ($approvedAmountMinor ?? $app->requested_amount_minor) : null,
                'decision_notes' => $notes,
                'reviewed_by_user_id' => $bankUser->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::query()->create([
                'tenant_id' => $org->id,
                'actor_type' => 'bank_officer',
                'actor_id' => $bankUser->id,
                'event_type' => 'finance_application_reviewed',
                'entity_type' => FinanceApplication::class,
                'entity_id' => $app->id,
                'payload_json' => [
                    'decision' => $decision,
                    'approved_amount_minor' => $app->approved_amount_minor,
                ],
                'created_at' => now(),
            ]);

            return $app->fresh();
        });
    }

    public function revokeBankConsent(Farmer $farmer, string $bankTenantId, string $channel = 'web'): ConsentEvent
    {
        return ConsentEvent::record(
            tenantId: $bankTenantId,
            farmerId: $farmer->id,
            scope: 'bank_plan_sharing',
            action: 'revoked',
            channel: $channel,
            source: 'farmer_consent_revocation',
            termsVersion: (string) config('betterlink.cdpa.current_policy_version', '2026.1')
        );
    }
}
