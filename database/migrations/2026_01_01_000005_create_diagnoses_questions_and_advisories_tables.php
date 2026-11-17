<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnoses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $table->foreignUuid('primary_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->foreignUuid('livestock_id')->nullable()->constrained('livestock')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('category', 40)->default('unclear')->index(); // pest|disease|nutrient_deficiency|nutrient_toxicity|weed|abiotic_stress|livestock_condition|unclear
            $table->string('growth_stage', 60)->nullable();
            $table->decimal('field_size_ha', 8, 2)->nullable();
            $table->unsignedSmallInteger('pfumvudza_plots_count')->nullable();
            $table->string('image_quality', 20)->default('good'); // good|poor|text_only
            $table->jsonb('candidates_json')->nullable();
            $table->jsonb('missing_information_json')->nullable();
            $table->string('final_diagnosis_name')->nullable();
            $table->string('final_diagnosis_code', 80)->nullable()->index();
            $table->decimal('confidence', 5, 4)->default(0.0000);
            $table->unsignedTinyInteger('questions_asked_count')->default(0);
            $table->string('status', 40)->default('intake')->index(); // intake|awaiting_better_image|clarifying|advisory_issued|escalated|reviewed|closed
            $table->string('escalation_reason')->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('agronomist_confirmed')->nullable();
            $table->string('agronomist_corrected_code', 80)->nullable();
            $table->text('agronomist_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'ward_id', 'final_diagnosis_code', 'created_at'], 'idx_diagnoses_outbreak_lookup');
            $table->index(['tenant_id', 'status', 'created_at']);
        });

        Schema::create('diagnosis_questions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->constrained('diagnoses')->cascadeOnDelete();
            $table->unsignedTinyInteger('question_order'); // 1..5
            $table->string('question_key', 80);
            $table->text('question_text');
            $table->jsonb('options_json')->nullable();
            $table->text('farmer_answer')->nullable();
            $table->decimal('confidence_before', 5, 4)->default(0.0000);
            $table->decimal('confidence_after', 5, 4)->nullable();
            $table->timestamp('asked_at');
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->unique(['diagnosis_id', 'question_order']);
        });

        Schema::create('advisories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->unique()->constrained('diagnoses')->cascadeOnDelete();
            $table->string('advisory_type', 40); // pest|disease|nutrient|weed|abiotic|livestock|non_chemical_escalation
            $table->jsonb('steps_json');
            $table->jsonb('products_json')->nullable();
            $table->jsonb('rates_json')->nullable();
            $table->jsonb('weather_window_json')->nullable();
            $table->text('safety_warnings_text');
            $table->text('rendered_message_text');
            $table->boolean('guardrail_passed')->default(true);
            $table->boolean('farmer_thumbs_up')->nullable();
            $table->unsignedTinyInteger('farmer_rating')->nullable(); // 1-10
            $table->text('farmer_feedback_text')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisories');
        Schema::dropIfExists('diagnosis_questions');
        Schema::dropIfExists('diagnoses');
    }
};
