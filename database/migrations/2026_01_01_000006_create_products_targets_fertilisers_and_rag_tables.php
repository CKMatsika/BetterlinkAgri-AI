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
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('manufacturer_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('sku', 60);
            $table->string('name');
            $table->string('brand_name')->nullable();
            $table->string('category', 40)->index(); // insecticide|fungicide|herbicide|acaricide|fertiliser|seed|foliar|animal_health
            $table->string('active_ingredient')->nullable();
            $table->string('mode_of_action_group', 60)->nullable(); // e.g. IRAC 28, FRAC 11, NPK 7:14:7
            $table->string('registration_number', 80)->nullable();
            $table->date('registration_expiry')->nullable();
            $table->string('hazard_colour_band', 20)->default('green'); // green|blue|amber|red|purple
            $table->boolean('is_restricted_use')->default(false);
            $table->decimal('agronomic_fit_rank_score', 5, 2)->default(80.00);
            $table->text('differentiation_notes')->nullable();
            $table->jsonb('pack_sizes_json')->nullable();
            $table->jsonb('ppe_requirements_json')->nullable();
            $table->jsonb('safety_warnings_json')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('product_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('document_type', 40)->index(); // product_label|agronomy_guide|pest_guide|nutrient_guide|fertiliser_table|livestock_guide
            $table->string('title');
            $table->string('language', 10)->default('en');
            $table->string('file_path');
            $table->string('file_hash', 64)->index();
            $table->string('ingestion_status', 30)->default('pending'); // pending|processing|completed|failed
            $table->unsignedInteger('chunks_count')->default(0);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_targets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('crop_id')->nullable()->constrained('crops')->nullOnDelete();
            $table->foreignUuid('livestock_id')->nullable()->constrained('livestock')->nullOnDelete();
            $table->string('target_type', 40); // pest|disease|weed|livestock_parasite|livestock_disease
            $table->string('target_code', 80)->index();
            $table->string('target_name_en');
            $table->string('target_name_sn')->nullable();
            $table->string('target_name_nd')->nullable();
            $table->decimal('dose_per_16l_knapsack_value', 10, 2);
            $table->string('dose_per_16l_knapsack_unit', 15); // ml|g
            $table->decimal('dose_per_ha_value', 10, 2);
            $table->string('dose_per_ha_unit', 15); // ml|L|g|kg
            $table->decimal('water_volume_per_ha_litres', 8, 2)->default(200.00);
            $table->text('mixing_order')->nullable();
            $table->text('application_timing_notes')->nullable();
            $table->unsignedSmallInteger('reapplication_interval_days')->nullable();
            $table->unsignedTinyInteger('max_applications_per_season')->nullable();
            $table->unsignedSmallInteger('phi_days')->default(0);
            $table->unsignedSmallInteger('rei_hours')->default(12);
            $table->foreignUuid('source_document_id')->nullable()->constrained('product_documents')->nullOnDelete();
            $table->unsignedSmallInteger('source_page_number')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'crop_id', 'target_code'], 'idx_product_targets_lookup');
        });

        Schema::create('fertiliser_recommendations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('crop_id')->constrained('crops')->cascadeOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('natural_region', 10)->default('ALL'); // I|IIa|IIb|III|IV|V|ALL
            $table->string('soil_type', 40)->default('ALL');
            $table->string('growth_stage', 60)->index(); // basal_planting|v4_v6_topdress|v8_v10_second_topdress|flowering_foliar
            $table->string('nutrient_addressed', 40)->index(); // N|P|K|S|Zn|B|Mg|Ca_Lime|compound_basal
            $table->string('fertiliser_type_name');
            $table->decimal('rate_kg_per_ha', 8, 2);
            $table->decimal('rate_grams_per_plant', 8, 2);
            $table->decimal('rate_grams_per_pfumvudza_station', 8, 2);
            $table->string('local_measure_equivalent');
            $table->string('placement_method', 60); // banding|side_dress|dollop_station|ring_placement|foliar_spray|broadcast_incorporate
            $table->text('timing_notes')->nullable();
            $table->text('precautions_notes');
            $table->foreignUuid('source_document_id')->nullable()->constrained('product_documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'crop_id', 'nutrient_addressed', 'natural_region'], 'idx_fert_rec_lookup');
        });

        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('product_document_id')->nullable()->constrained('product_documents')->cascadeOnDelete();
            $table->string('document_type', 40)->index();
            $table->string('crop_code', 40)->nullable()->index();
            $table->string('language', 10)->default('en');
            $table->string('title');
            $table->unsignedSmallInteger('chunk_index')->default(0);
            $table->unsignedSmallInteger('page_number')->nullable();
            $table->text('content');
            $table->jsonb('metadata_json')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'crop_code']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $dim = (int) config('betterlink.ai.vector_dimension', 1024);
            DB::statement("ALTER TABLE knowledge_chunks ADD COLUMN embedding vector({$dim});");
            DB::statement('CREATE INDEX idx_knowledge_chunks_embedding_hnsw ON knowledge_chunks USING hnsw (embedding vector_cosine_ops);');
        } else {
            Schema::table('knowledge_chunks', function (Blueprint $table): void {
                $table->json('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('fertiliser_recommendations');
        Schema::dropIfExists('product_targets');
        Schema::dropIfExists('product_documents');
        Schema::dropIfExists('products');
    }
};
