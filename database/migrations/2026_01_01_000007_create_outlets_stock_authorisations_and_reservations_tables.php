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
        Schema::create('outlets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('outlet_type', 40)->default('retail_branch'); // retail_branch|authorized_stockist|distributor_depot|agro_dealer
            $table->string('name');
            $table->string('address');
            $table->foreignUuid('province_id')->constrained('provinces')->restrictOnDelete();
            $table->foreignUuid('district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->jsonb('operating_hours_json')->nullable();
            $table->string('phone', 40);
            $table->string('whatsapp_number', 40)->nullable();
            $table->decimal('delivery_radius_km', 6, 2)->default(0.00);
            $table->decimal('delivery_fee_amount', 10, 2)->default(0.00);
            $table->string('delivery_fee_currency', 10)->default('USD'); // USD|ZIG
            $table->jsonb('accepted_payments_json')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'province_id', 'is_active']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE outlets ADD COLUMN location_point GEOGRAPHY(Point, 4326);');
            DB::statement('CREATE INDEX idx_outlets_location_gist ON outlets USING GIST (location_point);');
        }

        Schema::create('outlet_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('outlet_manager');
            $table->boolean('receives_whatsapp_alerts')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'user_id']);
        });

        Schema::create('outlet_stock', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('pack_size', 40);
            $table->string('quantity_band', 25)->default('in_stock')->index(); // in_stock|low_stock|out_of_stock
            $table->unsignedInteger('exact_quantity')->nullable();
            $table->decimal('price', 12, 2);
            $table->string('currency', 10)->default('USD'); // USD|ZIG
            $table->string('source', 30)->default('outlet_portal'); // outlet_portal|whatsapp_bot|csv_upload|erp_webhook
            $table->timestamp('last_updated_at');
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id', 'pack_size', 'currency'], 'uq_outlet_product_pack_curr');
            $table->index(['outlet_id', 'product_id', 'quantity_band']);
        });

        Schema::create('outlet_product_authorisation', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('authorised_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->boolean('is_authorised')->default(true)->index();
            $table->timestamps();

            $table->unique(['outlet_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_product_authorisation');
        Schema::dropIfExists('outlet_stock');
        Schema::dropIfExists('outlet_users');
        Schema::dropIfExists('outlets');
    }
};
