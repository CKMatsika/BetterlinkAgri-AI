<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_enterprises', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique(); // broilers|maize|soya_beans|sorghum|wheat|tobacco|dairy|horticulture
            $table->string('name_en');
            $table->string('name_sn')->nullable();
            $table->string('name_nd')->nullable();
            $table->string('category', 30); // livestock|grain|cash_crop|horticulture
            $table->string('default_unit', 30); // birds|hectares|cows
            $table->jsonb('verdict_rules_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('enterprise_question_banks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('enterprise_id')->constrained('plan_enterprises')->cascadeOnDelete();
            $table->string('question_key', 80);
            $table->unsignedSmallInteger('question_order')->default(1);
            $table->boolean('is_mandatory')->default(true);
            $table->text('question_text_en');
            $table->text('question_text_sn')->nullable();
            $table->text('question_text_nd')->nullable();
            $table->jsonb('options_json')->nullable();
            $table->jsonb('validation_rules_json')->nullable(); // e.g. max_cycles_per_year: 8, requires_irrigation: true
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['enterprise_id', 'question_key']);
        });

        Schema::create('benchmark_sources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->string('publication_year', 10)->nullable();
            $table->string('document_reference')->nullable();
            $table->timestamps();
        });

        Schema::create('benchmarks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('enterprise_code', 40)->index();
            $table->string('natural_region', 10)->default('ALL')->index();
            $table->string('metric_key', 80)->index(); // fcr|mortality_pct|cycle_days|yield_kg_per_ha|chick_cost_minor|feed_cost_per_bird_minor|etc.
            $table->string('value_type', 20)->default('integer_minor'); // integer_minor|decimal_2|integer
            $table->bigInteger('value_scaled'); // Minor units (cents) or integer*100 for percentages/FCR
            $table->bigInteger('range_low_scaled')->nullable();
            $table->bigInteger('range_high_scaled')->nullable();
            $table->string('unit', 40);
            $table->string('currency', 10)->nullable(); // USD|ZIG|null
            $table->foreignUuid('source_id')->nullable()->constrained('benchmark_sources')->nullOnDelete();
            $table->date('effective_date');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('unverified')->index(); // unverified|verified
            $table->timestamps();
            $table->softDeletes();

            $table->index(['enterprise_code', 'natural_region', 'metric_key', 'status'], 'idx_benchmarks_lookup');
        });

        // No TenantScope: national/regional output commodity prices with optional tenant_id override
        Schema::create('market_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('commodity_code', 60)->index(); // live_broiler|dressed_broiler_kg|maize_tonne|wheat_tonne|milk_litre
            $table->string('commodity_name');
            $table->string('region_or_district', 80)->default('National')->index();
            $table->string('grade', 40)->default('Standard');
            $table->string('unit', 30); // bird|kg|tonne|litre
            $table->bigInteger('price_minor'); // Integer minor units (cents)
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->string('source');
            $table->date('effective_date')->index();
            $table->string('status', 20)->default('unverified')->index(); // unverified|verified
            $table->timestamps();
            $table->softDeletes();

            $table->index(['commodity_code', 'region_or_district', 'currency', 'status'], 'idx_market_prices_lookup');
        });

        Schema::create('farm_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('enterprise_id')->constrained('plan_enterprises')->restrictOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->uuid('parent_plan_id')->nullable();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('title');
            $table->string('state', 40)->default('INTERVIEW')->index(); // INTERVIEW|PLAN_DRAFTED|PROJECTION_READY|REVIEW_REQUESTED|ACCEPTED|ABANDONED|ACTIVATED|IN_PROGRESS|CLOSED
            $table->string('language', 10)->default('en');
            $table->decimal('scale_value', 10, 2)->default(1.00);
            $table->string('scale_unit', 30)->default('hectares');
            $table->date('planned_start_date')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->bigInteger('exchange_rate_scaled')->default(255000); // USD->ZiG * 10000 (25.5000 = 255000)
            $table->bigInteger('inflation_pct_scaled')->default(0); // % * 100
            $table->string('viability_verdict', 40)->nullable()->index(); // viable|viable_with_conditions|marginal|not_viable
            $table->string('verdict_label', 60)->nullable(); // demo data, needs agronomist review | based on your own figures | verified
            $table->jsonb('verdict_reasons_json')->nullable();
            $table->jsonb('verdict_conditions_json')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('state_updated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farmer_id', 'state']);
        });

        Schema::table('farm_plans', function (Blueprint $table): void {
            $table->foreign('parent_plan_id')->references('id')->on('farm_plans')->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignUuid('active_farm_plan_id')->nullable()->after('assigned_user_id')->constrained('farm_plans')->nullOnDelete();
        });

        Schema::create('plan_interview_answers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('question_key', 80);
            $table->text('answer_text');
            $table->jsonb('parsed_value_json')->nullable();
            $table->boolean('is_flagged_assumption')->default(false);
            $table->string('flag_reason')->nullable();
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['farm_plan_id', 'question_key']);
        });

        Schema::create('plan_assumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('assumption_key', 80);
            $table->string('label');
            $table->bigInteger('value_scaled');
            $table->string('unit', 40);
            $table->string('currency', 10)->nullable();
            $table->string('source_type', 30); // benchmark|market_price|outlet_stock|farmer_override
            $table->uuid('reference_id')->nullable();
            $table->string('source_citation');
            $table->string('status', 20)->default('unverified'); // unverified|verified|farmer_supplied
            $table->boolean('is_outside_typical_range')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_projections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->unique()->constrained('farm_plans')->cascadeOnDelete();
            $table->string('currency', 10)->default('USD');
            $table->bigInteger('capital_cost_minor')->default(0);
            $table->bigInteger('working_capital_minor')->default(0);
            $table->bigInteger('total_cost_minor')->default(0);
            $table->bigInteger('total_revenue_minor')->default(0);
            $table->bigInteger('gross_margin_minor')->default(0);
            $table->bigInteger('net_margin_minor')->default(0);
            $table->bigInteger('peak_funding_need_minor')->default(0);
            $table->bigInteger('break_even_price_minor')->default(0);
            $table->bigInteger('break_even_volume_scaled')->default(0); // * 100
            $table->integer('payback_period_days')->default(0);
            $table->bigInteger('roi_pct_scaled')->default(0); // % * 100
            $table->jsonb('monthly_cashflow_json')->nullable();
            $table->jsonb('cost_breakdown_json')->nullable();
            $table->jsonb('sensitivity_table_json')->nullable();
            $table->timestamps();
        });

        Schema::create('plan_scenarios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('scenario_type', 20); // conservative|base|optimistic
            $table->string('currency', 10)->default('USD');
            $table->bigInteger('total_cost_minor');
            $table->bigInteger('total_revenue_minor');
            $table->bigInteger('net_margin_minor');
            $table->bigInteger('roi_pct_scaled');
            $table->timestamps();

            $table->unique(['farm_plan_id', 'scenario_type']);
        });

        Schema::create('plan_risk_flags', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('risk_category', 40); // disease|weather_region|market|power_water|financing|assumption
            $table->string('severity', 20)->default('medium'); // low|medium|high
            $table->text('description');
            $table->text('mitigation_note')->nullable();
            $table->boolean('is_resolved')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_checklist_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('title');
            $table->string('authority_or_body')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_checked')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->foreignUuid('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger_reason', 60); // unverified_benchmarks|farmer_requested|borderline_verdict|large_scale
            $table->string('status', 30)->default('pending'); // pending|in_review|approved|adjusted
            $table->text('agronomist_comments')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        // Designed for zero rewrite in P2; marketplace FKs added in Marketplace migrations M1/M3
        Schema::create('plan_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->cascadeOnDelete();
            $table->string('requirement_type', 30); // input|livestock_booking|feed|service|finance|offtake|labour|equipment
            $table->string('item_code', 80);
            $table->string('item_name');
            $table->bigInteger('quantity_scaled'); // * 100
            $table->string('unit', 30);
            $table->date('needed_by_date');
            $table->unsignedSmallInteger('lead_time_days')->default(7);
            $table->timestamp('activation_at')->index();
            $table->string('status', 30)->default('scheduled')->index(); // scheduled|activated|covered|fulfilled|skipped
            $table->timestamp('farmer_confirmed_activation_at')->nullable();
            $table->uuid('marketplace_request_id')->nullable()->index();
            $table->uuid('booking_id')->nullable()->index();
            $table->uuid('forward_quote_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'activation_at'], 'idx_plan_requirements_activation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_requirements');
        Schema::dropIfExists('plan_reviews');
        Schema::dropIfExists('plan_checklist_items');
        Schema::dropIfExists('plan_risk_flags');
        Schema::dropIfExists('plan_scenarios');
        Schema::dropIfExists('plan_projections');
        Schema::dropIfExists('plan_assumptions');
        Schema::dropIfExists('plan_interview_answers');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_farm_plan_id');
        });

        Schema::dropIfExists('farm_plans');
        Schema::dropIfExists('market_prices');
        Schema::dropIfExists('benchmarks');
        Schema::dropIfExists('benchmark_sources');
        Schema::dropIfExists('enterprise_question_banks');
        Schema::dropIfExists('plan_enterprises');
    }
};
