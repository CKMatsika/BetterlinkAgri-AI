<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\ConversationAuditResource\Pages;

use App\Filament\Tenant\Components\ConnectedFarmerProfilePanel;
use App\Filament\Tenant\Resources\ConversationAuditResource;
use App\Models\AuditLog;
use App\Models\Conversation;
use Filament\Resources\Pages\ViewRecord;

final class ViewConversationAudit extends ViewRecord
{
    protected static string $resource = ConversationAuditResource::class;

    protected static string $view = 'filament.tenant.resources.conversation-audit-view';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->getRecord();
        $profilePanel = app(ConnectedFarmerProfilePanel::class)->buildForConversation($conversation);

        $messages = $conversation->messages()->with('attachments')->orderBy('created_at')->get();
        $auditLogs = AuditLog::query()
            ->where('conversation_id', '=', $conversation->id)
            ->orderBy('created_at')
            ->get();

        return [
            'conversation' => $conversation,
            'profile' => $profilePanel,
            'messages' => $messages,
            'auditLogs' => $auditLogs,
        ];
    }
}
