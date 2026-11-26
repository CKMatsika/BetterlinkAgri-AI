<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\User;

final class MarketplaceOrderPolicy
{
    public function view(User|Farmer $actor, MarketplaceOrder $order): bool
    {
        if ($actor instanceof Farmer) {
            return $order->farmer_id === $actor->id;
        }

        $org = $actor->tenant;
        if ($org === null || !$org->organisation_type?->isRetailSeller()) {
            return false;
        }

        return $order->organisation_id === $org->id;
    }
}
