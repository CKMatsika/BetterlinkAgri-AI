<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FinanceApplicationResource\Pages;

use App\Filament\Tenant\Resources\FinanceApplicationResource;
use Filament\Resources\Pages\ListRecords;

final class ListFinanceApplications extends ListRecords
{
    protected static string $resource = FinanceApplicationResource::class;
}
