<?php

declare(strict_types=1);

use App\Enums\PlanState;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(PlanningTestFixturesSeeder::class);
});

function authenticatePlannerFarmer(mixed $testCase, string $phone, ?string $tenantSlug = null): string
{
    $otpRes = $testCase->postJson('/api/v1/planner/auth/request-otp', [
        'phone' => $phone,
        'tenant_slug' => $tenantSlug,
    ])->assertStatus(200);

    $verifyRes = $testCase->postJson('/api/v1/planner/auth/verify-otp', [
        'phone' => $phone,
        'otp' => $otpRes->json('debug_otp'),
        'tenant_slug' => $tenantSlug,
    ])->assertStatus(200);

    return (string) $verifyRes->json('token');
}

it('serves the web planner portal page and enterprise shortcuts', function (): void {
    $this->get('/planner')
        ->assertStatus(200)
        ->assertSee('What do you want to grow or raise?');

    $this->getJson('/api/v1/planner/enterprises')
        ->assertStatus(200)
        ->assertJsonFragment(['code' => 'broilers']);
});

it('requires phone OTP authentication and enforces IDOR, tenant scoping, and per-farmer rate limiting', function (): void {
    $this->getJson('/api/v1/planner/plans')->assertStatus(401);

    $tokenA = authenticatePlannerFarmer($this, '+263771222111');
    $tokenB = authenticatePlannerFarmer($this, '+263771222222');

    $planARes = $this->withToken($tokenA)->postJson('/api/v1/planner/plans', [
        'enterprise_code' => 'broilers',
        'scale_value' => 500,
    ])->assertStatus(201);

    $planAId = $planARes->json('plan.id');

    // Farmer B cannot read, answer, what-if, accept, or export PDF for Farmer A's plan
    $this->withToken($tokenB)->getJson("/api/v1/planner/plans/{$planAId}")->assertStatus(403);
    $this->withToken($tokenB)->postJson("/api/v1/planner/plans/{$planAId}/answer", [
        'question_key' => 'scale',
        'answer_text' => '100',
    ])->assertStatus(403);
    $this->withToken($tokenB)->postJson("/api/v1/planner/plans/{$planAId}/what-if", [
        'scale_value' => 100,
    ])->assertStatus(403);
    $this->withToken($tokenB)->postJson("/api/v1/planner/plans/{$planAId}/accept")->assertStatus(403);
    $this->withToken($tokenB)->get("/api/v1/planner/plans/{$planAId}/pdf")->assertStatus(403);

    // Tenant scoping: a second tenant cannot access Tenant A's plan
    Tenant::query()->create([
        'name' => 'Second Agri Tenant',
        'slug' => 'second-tenant',
        'is_active' => true,
        'default_currency' => 'USD',
    ]);
    $tokenTenantB = authenticatePlannerFarmer($this, '+263771222999', 'second-tenant');
    $this->withToken($tokenTenantB)->getJson("/api/v1/planner/plans/{$planAId}")->assertStatus(403);

    // Per-farmer rate limiting (60/min): Farmer B hits 429 within 60 additional requests
    $hit429 = false;
    for ($i = 0; $i < 61; $i++) {
        $rlRes = $this->withToken($tokenB)->getJson('/api/v1/planner/plans');
        if ($rlRes->status() === 429) {
            $hit429 = true;
            break;
        }
        expect($rlRes->status())->toBe(200);
    }
    expect($hit429)->toBeTrue();
    // Farmer A's bucket is independent and still allowed
    $this->withToken($tokenA)->getJson('/api/v1/planner/plans')->assertStatus(200);
});

it('completes the one-question-at-a-time web interview, generates projections, runs what-if, accepts, and exports DomPDF', function (): void {
    config()->set('betterlink.planning.allow_unverified_demo_numbers', true);
    $token = authenticatePlannerFarmer($this, '+263771222333');

    $createRes = $this->withToken($token)->postJson('/api/v1/planner/plans', [
        'goal_text' => 'I want to start 500 broilers',
        'language' => 'en',
        'currency' => 'USD',
    ])->assertStatus(201);

    $planId = $createRes->json('plan.id');
    expect($createRes->json('interview.completed'))->toBeFalse()
        ->and($createRes->json('interview.next_question.key'))->not->toBeNull();

    $payload = $createRes->json();
    $safety = 0;
    while (!$payload['interview']['completed'] && $safety < 15) {
        $safety++;
        $qKey = $payload['interview']['next_question']['key'];
        $ans = $qKey === 'scale' ? '500' : 'Yes';
        $payload = $this->withToken($token)->postJson("/api/v1/planner/plans/{$planId}/answer", [
            'question_key' => $qKey,
            'answer_text' => $ans,
            'language' => 'en',
        ])->assertStatus(200)->json();
    }

    expect($payload['interview']['completed'])->toBeTrue()
        ->and($payload['plan']['state'])->toBe(PlanState::PROJECTION_READY->value)
        ->and($payload['financials']['projection']['total_cost_minor'])->toBeGreaterThan(0)
        ->and($payload['schedule_placeholder']['status'])->toBe('phase_p2_placeholder')
        ->and($payload['coverage_placeholder']['status'])->toBe('phase_p2_placeholder');

    $baseRevenue = $payload['financials']['projection']['total_revenue_minor'];

    // What-if: double the scale to 1000 birds
    $whatIfRes = $this->withToken($token)->postJson("/api/v1/planner/plans/{$planId}/what-if", [
        'scale_value' => 1000,
    ])->assertStatus(200);

    expect($whatIfRes->json('financials.projection.total_revenue_minor'))->toBe($baseRevenue * 2);

    // Accept plan
    $acceptRes = $this->withToken($token)->postJson("/api/v1/planner/plans/{$planId}/accept")
        ->assertStatus(200);

    expect($acceptRes->json('plan.state'))->toBe(PlanState::ACCEPTED->value);

    // DomPDF export in English, Shona, Ndebele
    foreach (['en', 'sn', 'nd'] as $lang) {
        $pdfRes = $this->withToken($token)->get("/api/v1/planner/plans/{$planId}/pdf?language={$lang}")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'application/pdf');

        expect(str_starts_with($pdfRes->getContent(), '%PDF-1.'))->toBeTrue();
    }
});

it('withholds unverified demo numbers in web planner and PDF when allow_unverified_demo_numbers is false', function (): void {
    config()->set('betterlink.planning.allow_unverified_demo_numbers', false);
    $token = authenticatePlannerFarmer($this, '+263771222444');

    $createRes = $this->withToken($token)->postJson('/api/v1/planner/plans', [
        'enterprise_code' => 'broilers',
        'scale_value' => 500,
    ])->assertStatus(201);

    $planId = $createRes->json('plan.id');
    $payload = $createRes->json();
    $safety = 0;
    while (!$payload['interview']['completed'] && $safety < 15) {
        $safety++;
        $qKey = $payload['interview']['next_question']['key'];
        $payload = $this->withToken($token)->postJson("/api/v1/planner/plans/{$planId}/answer", [
            'question_key' => $qKey,
            'answer_text' => $qKey === 'scale' ? '500' : 'Yes',
        ])->assertStatus(200)->json();
    }

    expect($payload['financials']['numbers_hidden_pending_review'])->toBeTrue()
        ->and($payload['financials']['verdict_label'])->toBe('under agronomist review')
        ->and($payload['financials']['projection'])->toBeNull();

    $pdfRes = $this->withToken($token)->get("/api/v1/planner/plans/{$planId}/pdf?language=en")->assertStatus(200);
    expect(str_starts_with($pdfRes->getContent(), '%PDF-1.'))->toBeTrue();
});
