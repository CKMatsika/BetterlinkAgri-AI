<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\ConsentEvent;
use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\RequestRecipient;
use App\Models\User;

final class MarketplaceRequestPolicy
{
    public function view(User|Farmer $actor, MarketplaceRequest $request): bool
    {
        if ($actor instanceof Farmer) {
            return $request->farmer_id === $actor->id;
        }

        $org = $actor->tenant;
        if ($org === null) {
            return false;
        }

        $orgType = $org->organisation_type;
        if ($orgType === null) {
            return false;
        }

        // Manufacturers and Agritex (government_office) NEVER see individual farmer requests
        if ($orgType->isAggregateOnlyActor()) {
            return false;
        }

        // Retailers only see requests explicitly routed to them via request_recipients
        if ($orgType->isRetailSeller()) {
            return RequestRecipient::withoutGlobalScopes()
                ->where('request_id', '=', $request->id)
                ->where('organisation_id', '=', $org->id)
                ->exists();
        }

        // Distributors only see upstream-escalated requests
        if ($orgType === OrganisationType::DISTRIBUTOR) {
            return $request->status === MarketplaceRequestStatus::UPSTREAM_ESCALATED;
        }

        return false;
    }

    /**
     * Exact farmer location and phone are hidden until offer acceptance AND farmer consent.
     */
    public function viewExactFarmerContact(User|Farmer $actor, MarketplaceRequest $request): bool
    {
        if ($actor instanceof Farmer) {
            return $request->farmer_id === $actor->id;
        }

        $org = $actor->tenant;
        if ($org === null || !$org->organisation_type?->isRetailSeller()) {
            return false;
        }

        $hasAcceptedOrder = MarketplaceOrder::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->where('organisation_id', '=', $org->id)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if (!$hasAcceptedOrder) {
            return false;
        }

        return ConsentEvent::hasActiveConsent($org->id, $request->farmer_id, 'marketplace_contact_reveal')
            || ConsentEvent::hasActiveConsent(null, $request->farmer_id, 'marketplace_contact_reveal');
    }
}
