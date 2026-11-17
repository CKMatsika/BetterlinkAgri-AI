<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CurrencyCode;
use App\Enums\LanguageCode;
use App\Enums\PlanState;
use App\Models\EnterpriseQuestionBank;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\PlanEnterprise;
use App\Models\Tenant;
use App\Services\Planning\PlanFinancialEngine;
use App\Services\Planning\PlanInterviewEngine;
use App\Services\Planning\PlanPdfExportService;
use App\Services\Planning\PlanStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class FarmPlannerController
{
    public function __construct(
        private readonly PlanInterviewEngine $interviewEngine,
        private readonly PlanFinancialEngine $financialEngine,
        private readonly PlanStateMachine $stateMachine,
        private readonly PlanPdfExportService $pdfExportService
    ) {
    }

    public function enterprises(): JsonResponse
    {
        $enterprises = PlanEnterprise::query()
            ->where('is_active', '=', true)
            ->orderBy('name_en')
            ->get()
            ->map(fn (PlanEnterprise $e) => [
                'id' => $e->id,
                'code' => $e->code,
                'name_en' => $e->name_en,
                'name_sn' => $e->name_sn,
                'name_nd' => $e->name_nd,
                'category' => $e->category,
                'default_unit' => $e->default_unit,
            ]);

        return response()->json(['enterprises' => $enterprises]);
    }

    public function index(Request $request): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $tenantId = $request->attributes->get('planner_tenant_id');

        $plans = FarmPlan::query()
            ->with('enterprise')
            ->where('farmer_id', '=', $farmerId)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', '=', $tenantId))
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (FarmPlan $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'state' => $p->state?->value ?? (string) $p->state,
                'enterprise_code' => $p->enterprise?->code,
                'enterprise_name' => $p->enterprise?->name_en,
                'scale_value' => $p->scale_value,
                'scale_unit' => $p->scale_unit,
                'currency' => $p->currency?->value ?? (string) $p->currency,
                'viability_verdict' => $p->viability_verdict?->value,
                'verdict_label' => $p->verdict_label,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ]);

        return response()->json(['plans' => $plans]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enterprise_code' => ['nullable', 'string', 'max:64'],
            'goal_text' => ['nullable', 'string', 'max:500'],
            'language' => ['nullable', 'string', 'in:en,sn,nd'],
            'currency' => ['nullable', 'string', 'in:USD,ZiG,ZAR'],
            'scale_value' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $enterprise = $this->resolveEnterprise(
            $validated['enterprise_code'] ?? null,
            $validated['goal_text'] ?? null
        );

        if ($enterprise === null) {
            return response()->json(['message' => 'No active farm enterprise found.'], 422);
        }

        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $tenantId = $request->attributes->get('planner_tenant_id');
        $farmer = Farmer::query()->findOrFail($farmerId);

        $language = LanguageCode::tryFrom((string) ($validated['language'] ?? 'en')) ?? LanguageCode::ENGLISH;
        $currency = CurrencyCode::tryFrom((string) ($validated['currency'] ?? 'USD')) ?? CurrencyCode::USD;

        $title = !empty($validated['goal_text'])
            ? Str::limit(trim((string) $validated['goal_text']), 120)
            : $enterprise->name_en . ' Plan';

        $plan = FarmPlan::query()->create([
            'tenant_id' => $tenantId,
            'farmer_id' => $farmer->id,
            'enterprise_id' => $enterprise->id,
            'title' => $title,
            'state' => PlanState::INTERVIEW,
            'language' => $language,
            'scale_value' => $validated['scale_value'] ?? ($enterprise->code === 'broilers' ? 500 : 1),
            'scale_unit' => $enterprise->default_unit ?? 'units',
            'planned_start_date' => now()->addDays(14)->toDateString(),
            'currency' => $currency,
            'state_updated_at' => now(),
        ]);

        return response()->json($this->buildPlanPayload($plan, $language), 201);
    }

    public function show(Request $request, FarmPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        $language = LanguageCode::tryFrom((string) $request->query('language', $plan->language?->value ?? 'en'))
            ?? LanguageCode::ENGLISH;

        return response()->json($this->buildPlanPayload($plan, $language));
    }

    public function answer(Request $request, FarmPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        $validated = $request->validate([
            'question_key' => ['required', 'string', 'max:100'],
            'answer_text' => ['required', 'string', 'max:1000'],
            'parsed_value' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'in:en,sn,nd'],
        ]);

        $language = LanguageCode::tryFrom((string) ($validated['language'] ?? $plan->language?->value ?? 'en'))
            ?? LanguageCode::ENGLISH;

        $parsed = (array) ($validated['parsed_value'] ?? []);
        $answerText = trim((string) $validated['answer_text']);

        if ($validated['question_key'] === 'scale' && is_numeric($answerText)) {
            $plan->update(['scale_value' => (float) $answerText]);
            $parsed['numeric_value'] = (float) $answerText;
        } elseif ($validated['question_key'] === 'start_date' && strtotime($answerText) !== false) {
            $plan->update(['planned_start_date' => date('Y-m-d', strtotime($answerText))]);
        }

        $recordResult = $this->interviewEngine->recordAnswer(
            $plan,
            $validated['question_key'],
            $answerText,
            $parsed
        );

        $interview = $this->interviewEngine->getNextQuestionOrComplete($plan->fresh(), $language);

        if ($interview['completed'] === true) {
            $this->financialEngine->runProjections($plan->fresh());
        }

        $payload = $this->buildPlanPayload($plan->fresh(), $language);
        $payload['last_answer_feedback'] = $recordResult;

        return response()->json($payload);
    }

    public function whatIf(Request $request, FarmPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        $validated = $request->validate([
            'scale_value' => ['nullable', 'numeric', 'min:0.01'],
            'planned_start_date' => ['nullable', 'date'],
            'currency' => ['nullable', 'string', 'in:USD,ZiG,ZAR'],
            'output_price_minor' => ['nullable', 'integer', 'min:1'],
            'language' => ['nullable', 'string', 'in:en,sn,nd'],
        ]);

        $updates = [];
        if (isset($validated['scale_value'])) {
            $updates['scale_value'] = (float) $validated['scale_value'];
        }
        if (isset($validated['planned_start_date'])) {
            $updates['planned_start_date'] = $validated['planned_start_date'];
        }
        if (isset($validated['currency'])) {
            $updates['currency'] = CurrencyCode::from($validated['currency']);
        }
        if ($updates !== []) {
            $plan->update($updates);
        }

        $overrides = [];
        if (isset($validated['output_price_minor'])) {
            $code = $plan->enterprise?->code ?? 'broilers';
            $itemKey = $code === 'broilers' ? 'market_price_live_broiler' : 'market_price_' . $code;
            $overrides[$itemKey] = (int) $validated['output_price_minor'];
        }

        $this->financialEngine->runProjections($plan->fresh(), $overrides);

        $language = LanguageCode::tryFrom((string) ($validated['language'] ?? $plan->language?->value ?? 'en'))
            ?? LanguageCode::ENGLISH;

        return response()->json($this->buildPlanPayload($plan->fresh(), $language));
    }

    public function accept(Request $request, FarmPlan $plan): JsonResponse
    {
        $this->authorizePlan($request, $plan);

        if ($plan->state === PlanState::PROJECTION_READY) {
            $this->stateMachine->transitionTo($plan, PlanState::ACCEPTED, 'farmer_accepted_on_web_portal');
        }

        $language = LanguageCode::tryFrom((string) $request->input('language', $plan->language?->value ?? 'en'))
            ?? LanguageCode::ENGLISH;

        return response()->json($this->buildPlanPayload($plan->fresh(), $language));
    }

    public function pdf(Request $request, FarmPlan $plan): Response
    {
        $this->authorizePlan($request, $plan);

        $language = LanguageCode::tryFrom((string) $request->query('language', $plan->language?->value ?? 'en'))
            ?? LanguageCode::ENGLISH;

        $bytes = $this->pdfExportService->render($plan, $language);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="farm-plan-' . $plan->id . '.pdf"',
        ]);
    }

    private function authorizePlan(Request $request, FarmPlan $plan): void
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $tenantId = $request->attributes->get('planner_tenant_id');

        if ($plan->farmer_id !== $farmerId) {
            abort(403, 'You do not have access to this farm plan.');
        }

        if ($tenantId !== null && $plan->tenant_id !== $tenantId) {
            abort(403, 'Tenant scope mismatch for this farm plan.');
        }
    }

    private function resolveEnterprise(?string $code, ?string $goalText): ?PlanEnterprise
    {
        if ($code !== null && $code !== '') {
            $found = PlanEnterprise::query()->where('code', '=', $code)->where('is_active', '=', true)->first();
            if ($found !== null) {
                return $found;
            }
        }

        $text = strtolower((string) $goalText);
        $keywordMap = [
            'broilers' => ['broiler', 'chicken', 'poultry', 'huku', 'inkukhu'],
            'pfumvudza_maize' => ['pfumvudza'],
            'maize_rainfed' => ['maize', 'chibage', 'umbila', 'corn'],
            'wheat_winter' => ['wheat', 'gorosi'],
            'tobacco' => ['tobacco', 'fodya'],
            'dairy' => ['dairy', 'milk', 'mukaka'],
        ];

        foreach ($keywordMap as $mappedCode => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $found = PlanEnterprise::query()->where('code', '=', $mappedCode)->where('is_active', '=', true)->first();
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return PlanEnterprise::query()->where('is_active', '=', true)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlanPayload(FarmPlan $plan, LanguageCode $language): array
    {
        $plan->loadMissing(['enterprise', 'projection', 'riskFlags', 'checklistItems', 'scenarios', 'interviewAnswers']);

        $totalQuestions = EnterpriseQuestionBank::query()
            ->where('enterprise_id', '=', $plan->enterprise_id)
            ->where('is_active', '=', true)
            ->count();
        $answeredCount = $plan->interviewAnswers->count();
        $interview = $this->interviewEngine->getNextQuestionOrComplete($plan, $language);
        $farmerFinancials = $this->financialEngine->serializeForFarmer($plan, $plan->projection);

        return [
            'plan' => [
                'id' => $plan->id,
                'title' => $plan->title,
                'state' => $plan->state?->value ?? (string) $plan->state,
                'language' => $language->value,
                'currency' => $plan->currency?->value ?? (string) $plan->currency,
                'scale_value' => $plan->scale_value,
                'scale_unit' => $plan->scale_unit,
                'planned_start_date' => $plan->planned_start_date?->toDateString(),
                'accepted_at' => $plan->accepted_at?->toIso8601String(),
                'enterprise' => $plan->enterprise ? [
                    'code' => $plan->enterprise->code,
                    'name_en' => $plan->enterprise->name_en,
                    'name_sn' => $plan->enterprise->name_sn,
                    'name_nd' => $plan->enterprise->name_nd,
                ] : null,
            ],
            'interview' => [
                'completed' => $interview['completed'],
                'answered_count' => $answeredCount,
                'total_questions' => $totalQuestions,
                'progress_pct' => $totalQuestions > 0 ? (int) round(($answeredCount / $totalQuestions) * 100) : 100,
                'next_question' => $interview['question'] ?? null,
                'summary' => $interview['summary'] ?? null,
            ],
            'financials' => $farmerFinancials,
            'recommendations' => [
                'risk_flags' => $plan->riskFlags->map(fn ($r) => [
                    'category' => $r->risk_category,
                    'severity' => $r->severity,
                    'description' => $r->description,
                    'mitigation_note' => $r->mitigation_note,
                ])->values()->all(),
                'checklist' => $plan->checklistItems->map(fn ($c) => [
                    'title' => $c->title,
                    'authority_or_body' => $c->authority_or_body,
                    'description' => $c->description,
                    'is_completed' => (bool) $c->is_completed,
                ])->values()->all(),
            ],
            'schedule_placeholder' => [
                'status' => 'phase_p2_placeholder',
                'message' => 'Time-phased requirement activation & supplier quote windows activate in Phase P2.',
            ],
            'coverage_placeholder' => [
                'status' => 'phase_p2_placeholder',
                'message' => 'Forward-demand, finance referral & offtake coverage tracking activates in Phase P2.',
            ],
        ];
    }
}
