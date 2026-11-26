<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Farmer;
use App\Models\Offer;
use App\Models\User;

final class OfferPolicy
{
    public function view(User|Farmer $actor, Offer $offer): bool
    {
        if ($actor instanceof Farmer) {
            return $offer->request()->withoutGlobalScopes()->where('farmer_id', '=', $actor->id)->exists();
        }

        $org = $actor->tenant;
        if ($org === null || !$org->organisation_type?->isRetailSeller()) {
            return false;
        }

        // Retailers NEVER see other retailers' offers
        return $offer->organisation_id === $org->id;
    }

    public function accept(User|Farmer $actor, Offer $offer): bool
    {
        if (!$actor instanceof Farmer) {
            return false;
        }

        return $offer->request()->withoutGlobalScopes()->where('farmer_id', '=', $actor->id)->exists();
    }
}
