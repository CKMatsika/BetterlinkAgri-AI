<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LeadResource\Pages;

use App\Filament\Tenant\Resources\LeadResource;
use Filament\Resources\Pages\EditRecord;

final class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;
}
