<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->string('scope', 60)->index(); // cdpa_processing|marketing|gps_capture|share_exact_with_outlet|plan_creation|plan_share_rep|plan_share_suppliers|plan_share_bank|plan_share_offtaker|plan_forward_aggregates|marketplace|upstream_sharing|finance_sharing|agritex_sharing
            $table->string('action', 20); // granted|revoked
            $table->timestamp('occurred_at')->index();
            $table->string('terms_version', 30)->default('2026.1');
            $table->string('channel', 30)->default('whatsapp');
            $table->string('source', 60)->default('farmer_reply');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'farmer_id', 'scope', 'occurred_at'], 'idx_consent_events_lookup');
        });

        Schema::table('crops', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pfumvudza_plants_per_station')->default(2)->after('pfumvudza_supported');
        });
    }

    public function down(): void
    {
        Schema::table('crops', function (Blueprint $table): void {
            $table->dropColumn('pfumvudza_plants_per_station');
        });

        Schema::dropIfExists('consent_events');
    }
};
