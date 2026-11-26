<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganisationType;
use App\Models\DemandAggregate;
use App\Models\Farmer;
use App\Models\User;

final class DemandAggregatePolicy
{
    public function view(User|Farmer $actor, DemandAggregate $aggregate): bool
    {
        if ($actor instanceof Farmer) {
            return false;
        }

        $org = $actor->tenant;
        if ($org === null || $org->organisation_type === null) {
            return false;
        }

        if ($org->organisation_type === OrganisationType::GOVERNMENT_OFFICE) {
            // Agritex k >= 5 cell suppression rule
            return $aggregate->contributing_farmers_count >= 5;
        }

        if ($org->organisation_type === OrganisationType::BANK) {
            return false;
        }

        return $aggregate->organisation_id === $org->id;
    }
}
