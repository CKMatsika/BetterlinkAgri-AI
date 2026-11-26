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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('organisation_type', 40)
                ->default('retailer')
                ->after('tenant_type')
                ->index(); // manufacturer|distributor|retailer|bank|government_office|independent_retailer|offtaker
            $table->timestamp('upstream_data_sharing_consented_at')->nullable()->after('is_active');
        });

        // Backfill organisation_type from existing tenant_type without breaking legacy behaviour
        DB::table('tenants')->where('tenant_type', '=', 'manufacturer')->update(['organisation_type' => 'manufacturer']);
        DB::table('tenants')->where('tenant_type', '=', 'distributor')->update(['organisation_type' => 'distributor']);
        DB::table('tenants')->where('tenant_type', '=', 'agro_dealer')->update(['organisation_type' => 'retailer']);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['organisation_type', 'upstream_data_sharing_consented_at']);
        });
    }
};
