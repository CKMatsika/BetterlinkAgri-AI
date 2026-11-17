<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Verifies that production web/queue-worker contexts run exclusively on PostgreSQL
 * with PostGIS and pgvector enabled (never SQLite/Haversine or lexical fallbacks).
 */
final class ProductionDatabaseGuard
{
    private static bool $verifiedThisProcess = false;

    public static function assertReady(): void
    {
        if (self::$verifiedThisProcess || !app()->isProduction()) {
            return;
        }

        $driver = DB::getDriverName();
        if ($driver !== 'pgsql') {
            throw new RuntimeException(
                "Production startup check failed: DB_CONNECTION must be 'pgsql' with PostGIS and pgvector (got '{$driver}')."
            );
        }

        $extensions = DB::table('pg_extension')
            ->whereIn('extname', ['postgis', 'vector'])
            ->pluck('extname')
            ->all();

        if (!in_array('postgis', $extensions, true) || !in_array('vector', $extensions, true)) {
            throw new RuntimeException(
                'Production startup check failed: PostgreSQL extensions postgis and vector must both be enabled.'
            );
        }

        if (strtolower((string) config('betterlink.ai.default_provider', 'anthropic')) === 'fake') {
            throw new RuntimeException(
                'Production startup check failed: AI_DEFAULT_PROVIDER=fake is forbidden in production.'
            );
        }

        self::$verifiedThisProcess = true;
    }

    /**
     * @return array{driver: string, postgis: bool, pgvector: bool, ai_provider_safe: bool, ok: bool}
     */
    public static function status(): array
    {
        $driver = DB::getDriverName();
        $postgis = false;
        $pgvector = false;
        $aiProviderSafe = strtolower((string) config('betterlink.ai.default_provider', 'anthropic')) !== 'fake';

        if ($driver === 'pgsql') {
            $extensions = DB::table('pg_extension')
                ->whereIn('extname', ['postgis', 'vector'])
                ->pluck('extname')
                ->all();
            $postgis = in_array('postgis', $extensions, true);
            $pgvector = in_array('vector', $extensions, true);
        }

        return [
            'driver' => $driver,
            'postgis' => $postgis,
            'pgvector' => $pgvector,
            'ai_provider_safe' => $aiProviderSafe,
            'ok' => $driver === 'pgsql' && $postgis && $pgvector && $aiProviderSafe,
        ];
    }
}
