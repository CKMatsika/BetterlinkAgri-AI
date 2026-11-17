<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\PlanReviewQueueResource\Pages;

use App\Filament\Tenant\Resources\PlanReviewQueueResource;
use Filament\Resources\Pages\ListRecords;

final class ListPlanReviews extends ListRecords
{
    protected static string $resource = PlanReviewQueueResource::class;
}
