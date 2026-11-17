<?php

declare(strict_types=1);

namespace App\Enums;

enum RichCardType: string
{
    case DIAGNOSIS_RESULT = 'diagnosis_result';
    case RECOMMENDED_PRODUCT = 'recommended_product';
    case APPLICATION_GUIDANCE = 'application_guidance';
    case PROFILE_UPDATED = 'profile_updated';
    case EVENT_INVITE = 'event_invite';
    case OUTLET_LIST = 'outlet_list';
    case HANDOFF_SUMMARY = 'handoff_summary';
}
