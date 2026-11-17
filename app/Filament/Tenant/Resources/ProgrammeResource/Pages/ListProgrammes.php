<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ProgrammeResource\Pages;

use App\Filament\Tenant\Resources\ProgrammeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

final class ListProgrammes extends ListRecords
{
    protected static string $resource = ProgrammeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
