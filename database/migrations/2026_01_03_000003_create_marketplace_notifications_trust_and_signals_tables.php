<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('recipient_type', 20); // farmer|user|outlet
            $table->uuid('recipient_id');
            $table->foreignUuid('organisation_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('channel', 20); // reverb|fcm|whatsapp|sms|ussd|email
            $table->string('event_type', 60);
            $table->string('title')->nullable();
            $table->text('body');
            $table->jsonb('payload_json')->nullable();
            $table->string('status', 20)->default('queued')->index(); // queued|sent|delivered|read|failed
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index(['recipient_type', 'recipient_id', 'status'], 'idx_notifications_recipient_status');
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 20); // farmer|user
            $table->uuid('owner_id');
            $table->foreignUuid('organisation_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->jsonb('preferred_channels_json');
            $table->unsignedSmallInteger('fallback_timeout_seconds')->default(120);
            $table->jsonb('quiet_hours_json')->nullable();
            $table->boolean('is_muted')->default(false);
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id'], 'uq_notification_pref_owner');
        });

        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('owner_type', 20); // farmer|user
            $table->uuid('owner_id');
            $table->foreignUuid('organisation_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('platform', 20); // android|ios|web
            $table->string('token_hash', 64)->unique();
            $table->text('token_encrypted');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'is_active'], 'idx_device_tokens_owner_active');
        });

        Schema::create('seller_verifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organisation_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->nullable()->constrained('outlets')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending|verified|suspended
            $table->string('business_registration_number', 100)->nullable();
            $table->string('id_document_path')->nullable();
            $table->string('outlet_photo_path')->nullable();
            $table->text('verified_address')->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_or_suspension_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organisation_id', 'status'], 'idx_seller_verifications_org_status');
        });

        Schema::create('stock_signals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('category', 60)->nullable()->index();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('signal_type', 40); // out_of_stock_response|ring_exhausted|low_stock
            $table->bigInteger('quantity_requested_scaled')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['product_id', 'district_id', 'occurred_at'], 'idx_stock_signals_prod_dist_time');
        });

        // Anonymised event stream (intentionally no farmer_id)
        Schema::create('sale_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('category', 60)->nullable()->index();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->bigInteger('quantity_scaled');
            $table->bigInteger('total_amount_minor');
            $table->string('currency', 10)->default('USD');
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['organisation_id', 'occurred_at'], 'idx_sale_events_org_time');
            $table->index(['product_id', 'district_id', 'occurred_at'], 'idx_sale_events_prod_dist_time');
        });

        Schema::create('demand_aggregates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('granularity', 20)->default('weekly'); // daily|weekly|monthly
            $table->foreignUuid('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('category', 60)->nullable()->index();
            $table->foreignUuid('organisation_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('fulfilled_count')->default(0);
            $table->unsignedInteger('unmet_count')->default(0);
            $table->unsignedInteger('contributing_farmers_count')->default(0); // For k >= 5 Agritex suppression
            $table->bigInteger('total_quantity_scaled')->default(0);
            $table->bigInteger('total_sales_minor')->default(0);
            $table->string('currency', 10)->default('USD');
            $table->timestamps();

            $table->index(['period_start', 'district_id', 'product_id'], 'idx_demand_agg_period_dist_prod');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_aggregates');
        Schema::dropIfExists('sale_events');
        Schema::dropIfExists('stock_signals');
        Schema::dropIfExists('seller_verifications');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
