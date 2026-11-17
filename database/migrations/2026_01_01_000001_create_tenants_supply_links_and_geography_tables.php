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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('tenant_type', 40)->default('agro_dealer')->index(); // manufacturer|distributor|agro_dealer
            $table->string('recommendation_scope', 40)->default('own_plus_partner_brands'); // own_brands_only|own_plus_partner_brands
            $table->string('whatsapp_phone_number_id')->nullable()->unique();
            $table->string('whatsapp_business_account_id')->nullable();
            $table->text('whatsapp_access_token_encrypted')->nullable();
            $table->decimal('usd_to_zig_rate', 12, 4)->default(25.5000);
            $table->jsonb('persona_config')->nullable();
            $table->jsonb('boundaries_config')->nullable();
            $table->jsonb('outlet_ranking_weights')->nullable();
            $table->jsonb('crm_config')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_supply_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('manufacturer_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('distributor_or_retailer_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->jsonb('approved_product_ids_json')->nullable();
            $table->string('status', 30)->default('active')->index(); // pending|active|suspended|revoked
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['manufacturer_tenant_id', 'distributor_or_retailer_tenant_id'],
                'uq_tenant_supply_link_pair'
            );
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('province_id')->constrained('provinces')->cascadeOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->timestamps();

            $table->index(['province_id', 'name']);
        });

        Schema::create('wards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('district_id')->constrained('districts')->cascadeOnDelete();
            $table->unsignedSmallInteger('ward_number');
            $table->string('name');
            $table->string('natural_region', 10)->index(); // I, IIa, IIb, III, IV, V
            $table->timestamps();

            $table->unique(['district_id', 'ward_number']);
        });

        Schema::create('ward_centroids', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('ward_id')->unique()->constrained('wards')->cascadeOnDelete();
            $table->string('growth_point_name')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE ward_centroids ADD COLUMN centroid GEOGRAPHY(Point, 4326);');
            DB::statement('CREATE INDEX idx_ward_centroids_gist ON ward_centroids USING GIST (centroid);');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ward_centroids');
        Schema::dropIfExists('wards');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('tenant_supply_links');
        Schema::dropIfExists('tenants');
    }
};
