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
        Schema::create('requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignUuid('diagnosis_id')->nullable()->constrained('diagnoses')->nullOnDelete();
            $table->foreignUuid('origin_organisation_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('request_type', 30)->default('stock')->index(); // stock|booking|finance
            $table->string('status', 40)->default('DRAFT')->index();
            $table->string('source', 30)->default('app'); // ai_conversation|app|web|whatsapp|ussd|plan_schedule
            $table->foreignUuid('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->decimal('approx_latitude', 10, 7)->nullable();
            $table->decimal('approx_longitude', 10, 7)->nullable();
            $table->text('exact_location_encrypted')->nullable();
            $table->text('farmer_phone_encrypted')->nullable();
            $table->string('delivery_preference', 20)->default('either'); // pickup|delivery|either
            $table->date('needed_by_date')->nullable();
            $table->bigInteger('max_budget_minor')->nullable();
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->unsignedTinyInteger('current_ring')->default(0);
            $table->timestamp('ring_expires_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'expires_at'], 'idx_requests_status_expires');
            $table->index(['status', 'ring_expires_at'], 'idx_requests_status_ring_expires');
            $table->index(['farmer_id', 'status'], 'idx_requests_farmer_status');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE requests ADD COLUMN approx_location_point GEOGRAPHY(Point, 4326);');
            DB::statement('CREATE INDEX idx_requests_approx_location_gist ON requests USING GIST (approx_location_point);');
        }

        // Deferred FK from P1a plan_requirements -> requests (booking_id and forward_quote_id FKs added in their phases)
        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->foreign('marketplace_request_id')
                ->references('id')
                ->on('requests')
                ->nullOnDelete();
        });

        Schema::create('request_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('category', 60)->nullable()->index();
            $table->string('item_name');
            $table->bigInteger('quantity_scaled'); // * 100
            $table->string('unit', 30);
            $table->string('pack_size', 40)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('request_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->unsignedTinyInteger('ring_number')->default(1);
            $table->string('distance_band', 30)->default('0-10km');
            $table->timestamp('notified_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->string('response', 30)->nullable()->index(); // offered|declined|out_of_stock|ignored
            $table->timestamps();

            $table->unique(['request_id', 'outlet_id'], 'uq_request_recipient_outlet');
            $table->index(['organisation_id', 'response'], 'idx_request_recipients_org_resp');
        });

        Schema::create('offers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->cascadeOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->default('pending')->index(); // pending|accepted|rejected|withdrawn|expired|closed
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('total_price_minor');
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->bigInteger('quantity_available_scaled'); // * 100
            $table->string('fulfilment_type', 20)->default('pickup'); // pickup|delivery|both
            $table->bigInteger('delivery_fee_minor')->default(0);
            $table->timestamp('valid_until');
            $table->integer('rank_score_scaled')->nullable();
            $table->jsonb('rank_explanation_json')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['request_id', 'status'], 'idx_offers_request_status');
            $table->index(['organisation_id', 'status'], 'idx_offers_org_status');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->restrictOnDelete();
            $table->foreignUuid('offer_id')->unique()->constrained('offers')->restrictOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->restrictOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('status', 30)->default('created')->index(); // created|confirmed|ready_for_pickup|out_for_delivery|completed|disputed|cancelled
            $table->bigInteger('total_amount_minor');
            $table->bigInteger('delivery_fee_minor')->default(0);
            $table->string('currency', 10)->default('USD');
            $table->string('fulfilment_type', 20)->default('pickup'); // pickup|delivery
            $table->string('payment_method', 30)->default('cash_on_pickup'); // cash_on_pickup|ecocash|paynow|other
            $table->string('payment_status', 20)->default('unpaid'); // unpaid|paid|refunded
            $table->timestamp('farmer_contact_revealed_at')->nullable();
            $table->timestamp('farmer_confirmed_at')->nullable();
            $table->timestamp('seller_confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farmer_id', 'status'], 'idx_orders_farmer_status');
            $table->index(['organisation_id', 'status'], 'idx_orders_org_status');
        });

        Schema::create('order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('actor_type', 30); // farmer|retailer|system|admin
            $table->uuid('actor_id')->nullable();
            $table->string('event_type', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at'], 'idx_order_events_order_created');
        });

        Schema::create('ratings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('rater_type', 20); // farmer|retailer
            $table->uuid('rater_id');
            $table->string('ratee_type', 20); // farmer|retailer
            $table->uuid('ratee_id');
            $table->unsignedTinyInteger('score'); // 1..5
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['order_id', 'rater_type'], 'uq_rating_order_rater_type');
            $table->index(['ratee_type', 'ratee_id'], 'idx_ratings_ratee');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('request_recipients');
        Schema::dropIfExists('request_items');

        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->dropForeign(['marketplace_request_id']);
        });

        Schema::dropIfExists('requests');
    }
};
