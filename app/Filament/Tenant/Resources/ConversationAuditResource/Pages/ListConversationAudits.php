<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ConversationAuditResource\Pages;

use App\Filament\Tenant\Resources\ConversationAuditResource;
use Filament\Resources\Pages\ListRecords;

final class ListConversationAudits extends ListRecords
{
    protected static string $resource = ConversationAuditResource::class;
}
