<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrganisationType;

/**
 * Holds the active Marketplace actor context separately from TenantContext.
 * Never weakens TenantScope; governs visibility across marketplace tables.
 */
final class MarketplaceContext
{
    private ?string $actorType = null; // farmer|retailer|distributor|manufacturer|bank|agritex|platform_admin

    private ?string $actorId = null;

    private ?string $organisationId = null;

    private ?OrganisationType $organisationType = null;

    /** @var list<string> */
    private array $outletIds = [];

    /** @var list<string> */
    private array $assignedDistrictIds = [];

    private bool $bypass = false;

    public function setFarmer(string $farmerId): void
    {
        $this->reset();
        $this->actorType = 'farmer';
        $this->actorId = $farmerId;
    }

    /**
     * @param list<string> $outletIds
     * @param list<string> $assignedDistrictIds
     */
    public function setOrganisationActor(
        string $organisationId,
        OrganisationType $organisationType,
        ?string $userId = null,
        array $outletIds = [],
        array $assignedDistrictIds = []
    ): void {
        $this->reset();
        $this->organisationId = $organisationId;
        $this->organisationType = $organisationType;
        $this->actorId = $userId;
        $this->outletIds = $outletIds;
        $this->assignedDistrictIds = $assignedDistrictIds;
        $this->actorType = match ($organisationType) {
            OrganisationType::RETAILER, OrganisationType::INDEPENDENT_RETAILER => 'retailer',
            OrganisationType::DISTRIBUTOR => 'distributor',
            OrganisationType::MANUFACTURER => 'manufacturer',
            OrganisationType::BANK => 'bank',
            OrganisationType::GOVERNMENT_OFFICE => 'agritex',
            OrganisationType::OFFTAKER => 'offtaker',
        };
    }

    public function setPlatformAdmin(?string $userId = null): void
    {
        $this->reset();
        $this->actorType = 'platform_admin';
        $this->actorId = $userId;
    }

    public function setBypass(bool $bypass): void
    {
        $this->bypass = $bypass;
    }

    public function reset(): void
    {
        $this->actorType = null;
        $this->actorId = null;
        $this->organisationId = null;
        $this->organisationType = null;
        $this->outletIds = [];
        $this->assignedDistrictIds = [];
        $this->bypass = false;
    }

    public function isActive(): bool
    {
        return $this->actorType !== null && !$this->bypass;
    }

    public function isBypassing(): bool
    {
        return $this->bypass || $this->actorType === 'platform_admin';
    }

    public function getActorType(): ?string
    {
        return $this->actorType;
    }

    public function getActorId(): ?string
    {
        return $this->actorId;
    }

    public function getOrganisationId(): ?string
    {
        return $this->organisationId;
    }

    public function getOrganisationType(): ?OrganisationType
    {
        return $this->organisationType;
    }

    /** @return list<string> */
    public function getOutletIds(): array
    {
        return $this->outletIds;
    }

    /** @return list<string> */
    public function getAssignedDistrictIds(): array
    {
        return $this->assignedDistrictIds;
    }
}
