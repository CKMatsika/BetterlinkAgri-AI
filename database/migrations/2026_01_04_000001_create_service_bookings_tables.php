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
        Schema::create('service_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('service_type', 40)->index(); // tillage|spraying|soil_testing|transport|veterinary|agronomy_visit
            $table->foreignUuid('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->decimal('base_latitude', 10, 7);
            $table->decimal('base_longitude', 10, 7);
            $table->unsignedSmallInteger('coverage_radius_km')->default(50);
            $table->bigInteger('unit_rate_minor');
            $table->string('rate_unit', 30)->default('per_ha'); // per_ha|per_km|per_visit|per_sample
            $table->string('currency', 10)->default('USD');
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_verified')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['service_type', 'is_active', 'is_verified'], 'idx_service_providers_type_active');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE service_providers ADD COLUMN location_point GEOGRAPHY(Point, 4326);');
            DB::statement('CREATE INDEX idx_service_providers_location_gist ON service_providers USING GIST (location_point);');
        }

        Schema::create('service_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('service_provider_id')->constrained('service_providers')->restrictOnDelete();
            $table->foreignUuid('organisation_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('farm_plan_id')->nullable()->constrained('farm_plans')->nullOnDelete();
            $table->foreignUuid('plan_requirement_id')->nullable()->constrained('plan_requirements')->nullOnDelete();
            $table->string('service_type', 40)->index();
            $table->string('status', 30)->default('requested')->index(); // requested|confirmed|in_progress|completed|cancelled|disputed
            $table->date('scheduled_date');
            $table->bigInteger('quantity_scaled'); // * 100
            $table->string('unit', 30)->default('ha');
            $table->bigInteger('quoted_amount_minor');
            $table->string('currency', 10)->default('USD');
            $table->foreignUuid('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->decimal('approx_latitude', 10, 7)->nullable();
            $table->decimal('approx_longitude', 10, 7)->nullable();
            $table->timestamp('farmer_contact_revealed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farmer_id', 'status'], 'idx_service_bookings_farmer_status');
            $table->index(['organisation_id', 'status'], 'idx_service_bookings_org_status');
        });

        // Deferred FK from P1a plan_requirements.booking_id -> service_bookings.id
        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->foreign('booking_id')
                ->references('id')
                ->on('service_bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->dropForeign(['booking_id']);
        });

        Schema::dropIfExists('service_bookings');
        Schema::dropIfExists('service_providers');
    }
};
