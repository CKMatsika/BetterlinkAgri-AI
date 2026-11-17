<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\BenchmarkResource\Pages;

use App\Filament\Tenant\Resources\BenchmarkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListBenchmarks extends ListRecords
{
    protected static string $resource = BenchmarkResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
