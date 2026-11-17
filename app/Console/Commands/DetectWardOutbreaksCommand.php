<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Diagnosis\OutbreakDetectionService;
use Illuminate\Console\Command;

final class DetectWardOutbreaksCommand extends Command
{
    protected $signature = 'betterlink:detect-outbreaks';

    protected $description = 'Cluster 7-day diagnoses by ward/district/crop and generate OutbreakSignals and alert campaigns.';

    public function handle(OutbreakDetectionService $outbreakService): int
    {
        $signals = $outbreakService->scanAndClusterOutbreaks();
        $this->info('Detected/updated ' . count($signals) . ' ward outbreak signals.');

        return self::SUCCESS;
    }
}
