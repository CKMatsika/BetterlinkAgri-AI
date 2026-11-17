<?php

declare(strict_types=1);

namespace App\Enums;

enum ConversationMode: string
{
    case AI_ACTIVE = 'ai_active';
    case HUMAN_TAKEOVER = 'human_takeover';
}
