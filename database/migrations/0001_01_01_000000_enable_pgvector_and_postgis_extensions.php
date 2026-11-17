<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
            DB::statement('CREATE EXTENSION IF NOT EXISTS postgis;');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP EXTENSION IF EXISTS postgis CASCADE;');
            DB::statement('DROP EXTENSION IF EXISTS vector CASCADE;');
        }
    }
};
