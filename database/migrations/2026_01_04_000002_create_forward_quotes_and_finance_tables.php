<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forward_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('offtaker_organisation_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('farm_plan_id')->nullable()->constrained('farm_plans')->nullOnDelete();
            $table->foreignUuid('plan_requirement_id')->nullable()->constrained('plan_requirements')->nullOnDelete();
            $table->string('commodity_code', 60)->index();
            $table->string('commodity_name');
            $table->string('grade', 40)->nullable();
            $table->bigInteger('quantity_scaled'); // * 100
            $table->string('unit', 30)->default('tonnes');
            $table->bigInteger('quoted_unit_price_minor')->nullable();
            $table->bigInteger('total_value_minor')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->date('delivery_window_start');
            $table->date('delivery_window_end');
            $table->string('status', 30)->default('requested')->index(); // requested|quoted|accepted|declined|expired|contracted
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('farmer_contact_revealed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['offtaker_organisation_id', 'status'], 'idx_forward_quotes_offtaker_status');
            $table->index(['farmer_id', 'status'], 'idx_forward_quotes_farmer_status');
        });

        // Final deferred FK from P1a plan_requirements.forward_quote_id -> forward_quotes.id
        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->foreign('forward_quote_id')
                ->references('id')
                ->on('forward_quotes')
                ->nullOnDelete();
        });

        Schema::create('finance_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('farmer_id')->constrained('farmers')->cascadeOnDelete();
            $table->foreignUuid('bank_organisation_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('farm_plan_id')->constrained('farm_plans')->restrictOnDelete();
            $table->foreignUuid('forward_quote_id')->nullable()->constrained('forward_quotes')->nullOnDelete();
            $table->foreignUuid('consent_event_id')->constrained('consent_events')->restrictOnDelete();
            $table->bigInteger('requested_amount_minor');
            $table->bigInteger('approved_amount_minor')->nullable();
            $table->string('currency', 10)->default('USD');
            $table->string('purpose', 40)->default('input_finance'); // input_finance|working_capital|equipment
            $table->string('status', 30)->default('submitted')->index(); // submitted|under_review|approved|declined|disbursed|withdrawn
            $table->jsonb('plan_snapshot_json');
            $table->text('decision_notes')->nullable();
            $table->foreignUuid('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bank_organisation_id', 'status'], 'idx_finance_apps_bank_status');
            $table->index(['farmer_id', 'status'], 'idx_finance_apps_farmer_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_applications');

        Schema::table('plan_requirements', function (Blueprint $table): void {
            $table->dropForeign(['forward_quote_id']);
        });

        Schema::dropIfExists('forward_quotes');
    }
};
