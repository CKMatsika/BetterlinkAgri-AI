<?php

declare(strict_types=1);

use App\Enums\ConversationState;
use App\Enums\DiagnosisCategory;
use App\Enums\LanguageCode;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Tenant;
use App\Services\Diagnosis\AdaptiveQuestionEngine;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('asks one discriminative question at a time when confidence < 0.80 and stops once confidence >= 0.80', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263772111222', 'Taurai Moyo');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::CLARIFYING_QUESTIONS,
        'language' => LanguageCode::ENGLISH,
    ]);

    $diagnosis = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'growth_stage' => 'V6_vegetative',
        'field_size_ha' => 1.0,
        'candidates_json' => [
            [
                'code' => 'fall_armyworm',
                'name' => 'Fall Armyworm',
                'local_names' => ['sn' => 'Gonye remunhava', 'nd' => 'Amacimbi'],
                'type' => 'pest',
                'confidence' => 0.72,
                'visible_evidence' => ['Window-pane damage'],
                'look_alikes' => ['Maize Stalk Borer'],
            ],
            [
                'code' => 'maize_stalk_borer',
                'name' => 'Maize Stalk Borer',
                'local_names' => ['sn' => 'Chiburi', 'nd' => 'Isibungu'],
                'type' => 'pest',
                'confidence' => 0.20,
                'visible_evidence' => [],
                'look_alikes' => [],
            ],
        ],
        'final_diagnosis_name' => 'Fall Armyworm',
        'final_diagnosis_code' => 'fall_armyworm',
        'confidence' => 0.72,
        'questions_asked_count' => 0,
        'status' => 'clarifying',
    ]);

    $engine = app(AdaptiveQuestionEngine::class);

    // Turn 1: confidence is 0.72 (< 0.80), so it must ask Question 1
    $step1 = $engine->evaluateNextStep($diagnosis, $tenant, LanguageCode::ENGLISH);
    expect($step1['decision'])->toBe('ask_question')
        ->and($step1['question']->questionOrder)->toBe(1)
        ->and($step1['question']->questionKey)->toBe('insect_head_and_tail_markings');

    // Farmer answers option 1 ("faw_signs" -> boosts fall_armyworm by +0.16 to 0.88)
    $rescore = $engine->recordAnswerAndRescore($diagnosis, 'faw_signs');
    expect($rescore['new_confidence'])->toBeGreaterThanOrEqual(0.80);

    // Turn 2: confidence >= 0.80 and field_size_ha & growth_stage are known -> stops asking immediately
    $step2 = $engine->evaluateNextStep($diagnosis->fresh(), $tenant, LanguageCode::ENGLISH);
    expect($step2['decision'])->toBe('proceed_to_advisory')
        ->and($step2['stated_uncertainty'])->toBeFalse();
});

it('escalates to human agronomist queue when 5 questions are asked and confidence remains below 0.55', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263772333444', 'Chipo Dube');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::CLARIFYING_QUESTIONS,
    ]);

    $diagnosis = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'category' => DiagnosisCategory::PEST,
        'confidence' => 0.42,
        'questions_asked_count' => 5,
        'status' => 'clarifying',
    ]);

    $engine = app(AdaptiveQuestionEngine::class);
    $step = $engine->evaluateNextStep($diagnosis, $tenant, LanguageCode::ENGLISH);

    expect($step['decision'])->toBe('escalate_low_confidence');
});
