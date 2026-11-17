<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->decimal('area_ha', 8, 2)->nullable();
            $table->string('need_summary')->nullable();
            $table->string('purchase_window', 40)->default('within_14_days');
            $table->string('temperature', 20)->default('warm')->index(); // cold|warm|hot
            $table->unsignedTinyInteger('intent_score')->default(50);
            $table->jsonb('score_reasons_json')->nullable();
            $table->jsonb('requested_products_json')->nullable();
            $table->decimal('estimated_value', 12, 2)->default(0.00);
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->boolean('needs_credit_or_contract')->default(false);
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('meeting_status', 30)->default('none'); // none|proposed|confirmed|completed
            $table->string('crm_status', 30)->default('draft')->index(); // draft|ready_for_crm|synced|failed
            $table->string('external_crm_id')->nullable();
            $table->string('status', 30)->default('new')->index(); // new|accepted|contacted|quoted|won|lost
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'temperature']);
        });

        Schema::create('lead_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 40);
            $table->text('notes')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->jsonb('basket_items_json');
            $table->string('status', 35)->default('pending')->index(); // pending|confirmed|rejected|alternative_proposed|expired|picked_up|cancelled
            $table->text('rejection_reason')->nullable();
            $table->text('alternative_proposal_text')->nullable();
            $table->timestamp('sla_due_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('pickup_reminder_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['outlet_id', 'status', 'sla_due_at']);
        });

        Schema::create('sales_attribution', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignUuid('reservation_id')->nullable()->constrained('reservations')->nullOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->decimal('quantity_sold', 10, 2);
            $table->decimal('total_amount', 12, 2);
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->boolean('is_first_ai_engaged_sale')->default(false);
            $table->boolean('is_upsell_on_engaged_farmer')->default(false);
            $table->timestamp('attributed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'outlet_id', 'attributed_at']);
        });

        Schema::create('handoffs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('handoff_type', 30)->index(); // sales_rep|outlet_manager|agronomist|vet
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('priority', 25)->default('normal'); // normal|high|urgent_outbreak
            $table->string('reason');
            $table->jsonb('context_summary_json')->nullable();
            $table->string('status', 30)->default('pending')->index(); // pending|accepted|rejected|reassigned|resolved|sla_breached
            $table->timestamp('sla_due_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('rep_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meeting_type', 30)->default('phone_call'); // phone_call|whatsapp_call|farm_visit
            $table->jsonb('proposed_slots_json');
            $table->timestamp('confirmed_starts_at')->nullable();
            $table->timestamp('confirmed_ends_at')->nullable();
            $table->string('ics_file_path')->nullable();
            $table->string('status', 30)->default('proposed'); // proposed|confirmed|completed|cancelled
            $table->text('visit_notes')->nullable();
            $table->jsonb('visit_photos_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('programmes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->string('target_stage', 60)->nullable();
            $table->jsonb('enrolment_rules_json')->nullable();
            $table->jsonb('stage_messages_json')->nullable();
            $table->jsonb('monthly_priority_question_json')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('programme_enrolments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('farm_id')->nullable()->constrained('farms')->nullOnDelete();
            $table->string('current_stage', 60)->nullable();
            $table->string('last_priority_response')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamp('enrolled_at');
            $table->timestamps();

            $table->unique(['programme_id', 'farmer_id']);
        });

        Schema::create('campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('campaign_type', 40); // outbreak_alert|seasonal_advisory|stock_arrival|pfumvudza_reminder|event_invite
            $table->jsonb('segment_filter_json')->nullable();
            $table->string('whatsapp_template_name')->nullable();
            $table->string('whatsapp_template_status', 30)->default('approved'); // draft|submitted|approved|rejected
            $table->text('body_en');
            $table->text('body_sn')->nullable();
            $table->text('body_nd')->nullable();
            $table->unsignedSmallInteger('throttle_per_minute')->default(60);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 30)->default('draft'); // draft|scheduled|running|completed|cancelled
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('campaign_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('status', 25)->default('queued'); // queued|sent|delivered|read|replied|failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'farmer_id']);
        });

        Schema::create('events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->string('title');
            $table->string('event_type', 30)->default('field_day'); // field_day|demo|webinar
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('venue_or_link');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('reminder_24h_sent')->default(false);
            $table->boolean('reminder_1h_sent')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE events ADD COLUMN location_point GEOGRAPHY(Point, 4326);');
        }

        Schema::create('event_rsvps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('rsvp_status', 25)->default('invited'); // invited|going|maybe|declined|attended
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'farmer_id']);
        });

        Schema::create('follow_ups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignUuid('diagnosis_id')->constrained('diagnoses')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->timestamp('scheduled_for')->index();
            $table->unsignedTinyInteger('reminders_sent_count')->default(0);
            $table->foreignUuid('followup_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('outcome', 30)->default('pending'); // pending|resolved|improving|worsened|no_reply
            $table->text('comparison_summary')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('weather_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ward_id')->constrained('wards')->cascadeOnDelete();
            $table->timestamp('fetched_at');
            $table->timestamp('valid_until')->index();
            $table->decimal('temp_celsius', 5, 2);
            $table->unsignedTinyInteger('humidity_pct');
            $table->decimal('wind_speed_kmh', 5, 2);
            $table->unsignedTinyInteger('rain_probability_pct');
            $table->boolean('is_safe_to_spray')->default(true);
            $table->string('spray_window_summary');
            $table->jsonb('forecast_json')->nullable();
            $table->timestamps();
        });

        Schema::create('outbreak_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('ward_id')->constrained('wards')->cascadeOnDelete();
            $table->foreignUuid('district_id')->constrained('districts')->cascadeOnDelete();
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->string('target_code', 80)->index();
            $table->unsignedSmallInteger('cases_count_7d');
            $table->decimal('avg_confidence', 5, 4);
            $table->string('severity', 25)->default('warning'); // watch|warning|outbreak
            $table->foreignUuid('alert_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->timestamp('detected_at');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50)->index(); // state_transition|ai_diagnosis_turn|guardrail_check|location_lookup|reservation_event|human_takeover|opt_out
            $table->string('from_state', 50)->nullable();
            $table->string('to_state', 50)->nullable();
            $table->string('attachment_sha256', 64)->nullable();
            $table->jsonb('candidates_json')->nullable();
            $table->jsonb('questions_json')->nullable();
            $table->jsonb('tool_calls_json')->nullable();
            $table->jsonb('rag_chunk_ids_json')->nullable();
            $table->jsonb('guardrail_result_json')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbreak_signals');
        Schema::dropIfExists('weather_snapshots');
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('event_rsvps');
        Schema::dropIfExists('events');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('programme_enrolments');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('meetings');
        Schema::dropIfExists('handoffs');
        Schema::dropIfExists('sales_attribution');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('leads');
    }
};
