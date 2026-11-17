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
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('district_id')->nullable()->constrained('districts')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->text('phone_encrypted')->nullable();
            $table->string('whatsapp_number', 30)->nullable();
            $table->string('role', 40)->default('sales_rep')->index(); // super_admin|tenant_admin|agronomist|sales_rep|outlet_manager
            $table->jsonb('languages_json')->nullable();
            $table->jsonb('availability_schedule_json')->nullable();
            $table->boolean('is_available_for_handoff')->default(true)->index();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'role', 'is_available_for_handoff']);
        });

        // Global farmer identity keyed by phone_hash (HMAC-SHA256 of E.164 phone)
        Schema::create('farmers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('phone_hash', 64)->unique();
            $table->text('phone_encrypted');
            $table->text('full_name_encrypted')->nullable();
            $table->text('national_id_encrypted')->nullable();
            $table->string('preferred_language', 10)->default('en'); // en|sn|nd
            $table->string('farming_sector', 40)->nullable(); // communal|a1|a2|small_scale_commercial|large_scale_commercial
            $table->boolean('is_pfumvudza_enrolled')->default(false);
            $table->boolean('is_contract_farmer')->default(false);
            $table->jsonb('suppliers_used_json')->nullable();
            $table->string('preferred_contact_time', 30)->default('any');
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Per-tenant CDPA & WhatsApp Opt-In Consent
        Schema::create('consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('channel', 30)->default('whatsapp');
            $table->string('status', 30)->default('pending')->index(); // pending|opted_in|opted_out
            $table->boolean('data_processing_consent')->default(false);
            $table->boolean('marketing_consent')->default(false);
            $table->string('policy_version', 20)->default('2026.1');
            $table->string('opt_out_keyword_used', 20)->nullable(); // STOP|MIRA|MANA
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'farmer_id']);
        });

        // Per-tenant explicit GPS & outlet coordinate sharing consent
        Schema::create('location_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->boolean('gps_capture_consent')->default(false);
            $table->boolean('share_exact_with_outlet_consent')->default(false);
            $table->timestamp('consented_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'farmer_id']);
        });

        Schema::create('farmer_locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->text('latitude_encrypted')->nullable();
            $table->text('longitude_encrypted')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2)->nullable();
            $table->string('source', 40); // whatsapp_pin|live_location|app_gps|saved_farm|ward_centroid
            $table->boolean('is_approximate')->default(false);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index(['farmer_id', 'captured_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE farmer_locations ADD COLUMN location_point GEOGRAPHY(Point, 4326);');
            DB::statement('CREATE INDEX idx_farmer_locations_gist ON farmer_locations USING GIST (location_point);');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farmer_locations');
        Schema::dropIfExists('location_consents');
        Schema::dropIfExists('consents');
        Schema::dropIfExists('farmers');
        Schema::dropIfExists('users');
    }
};
