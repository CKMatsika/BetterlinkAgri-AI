<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\SprayingWindowData;
use App\Models\Ward;

interface WeatherProviderContract
{
    public function getSprayingWindowForWard(Ward $ward): SprayingWindowData;
}
