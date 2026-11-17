<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_sn')->nullable();
            $table->string('name_nd')->nullable();
            $table->string('season_type', 30)->default('summer'); // summer|winter|perennial|all_year
            $table->unsignedSmallInteger('default_row_spacing_cm')->default(90);
            $table->unsignedSmallInteger('default_inrow_spacing_cm')->default(30);
            $table->boolean('pfumvudza_supported')->default(false);
            $table->timestamps();
        });

        Schema::create('livestock', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name_en');
            $table->string('name_sn')->nullable();
            $table->string('name_nd')->nullable();
            $table->string('category', 30)->index(); // cattle|goats|poultry
            $table->timestamps();
        });

        Schema::create('farms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('ward_id')->nullable()->constrained('wards')->nullOnDelete();
            $table->string('name')->nullable();
            $table->string('natural_region', 10)->nullable()->index(); // I, IIa, IIb, III, IV, V
            $table->decimal('total_area_ha', 8, 2)->nullable();
            $table->decimal('cultivated_area_ha', 8, 2)->nullable();
            $table->string('soil_type', 40)->nullable(); // sand|sandy_loam|clay_loam|red_clay|black_vertisol
            $table->decimal('soil_ph', 4, 2)->nullable();
            $table->boolean('is_pfumvudza_plot')->default(false);
            $table->unsignedSmallInteger('pfumvudza_plots_count')->default(0);
            $table->jsonb('crops_grown_json')->nullable();
            $table->jsonb('livestock_kept_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['farmer_id', 'ward_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('farms');
        Schema::dropIfExists('livestock');
        Schema::dropIfExists('crops');
    }
};
