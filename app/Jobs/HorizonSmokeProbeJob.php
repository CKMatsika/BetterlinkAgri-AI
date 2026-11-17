<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

final class HorizonSmokeProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $probeId,
        public readonly string $label = 'immediate'
    ) {
    }

    public function handle(): void
    {
        Cache::put("horizon_probe:{$this->probeId}", [
            'probe_id' => $this->probeId,
            'label' => $this->label,
            'executed_at' => now()->toIso8601String(),
        ], now()->addMinutes(10));
    }
}
