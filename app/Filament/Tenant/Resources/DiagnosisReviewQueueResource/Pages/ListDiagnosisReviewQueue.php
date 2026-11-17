<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\DiagnosisReviewQueueResource\Pages;

use App\Filament\Tenant\Resources\DiagnosisReviewQueueResource;
use Filament\Resources\Pages\ListRecords;

final class ListDiagnosisReviewQueue extends ListRecords
{
    protected static string $resource = DiagnosisReviewQueueResource::class;
}
