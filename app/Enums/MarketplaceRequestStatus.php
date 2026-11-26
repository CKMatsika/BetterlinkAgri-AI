<?php

declare(strict_types=1);

namespace App\Enums;

enum MarketplaceRequestStatus: string
{
    case DRAFT = 'DRAFT';
    case BROADCAST_RING_1 = 'BROADCAST_RING_1';
    case BROADCAST_RING_2 = 'BROADCAST_RING_2';
    case BROADCAST_RING_3 = 'BROADCAST_RING_3';
    case OFFERS_RECEIVED = 'OFFERS_RECEIVED';
    case UPSTREAM_ESCALATED = 'UPSTREAM_ESCALATED';
    case OFFER_ACCEPTED = 'OFFER_ACCEPTED';
    case FULFILMENT = 'FULFILMENT';
    case COMPLETED = 'COMPLETED';
    case RATED = 'RATED';
    case DISPUTED = 'DISPUTED';
    case EXPIRED = 'EXPIRED';
    case UNFULFILLED = 'UNFULFILLED';
    case CANCELLED = 'CANCELLED';
}
