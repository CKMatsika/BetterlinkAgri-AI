<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Request-scoped & job-scoped Tenant and Outlet context container.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?Tenant $tenant = null;

    /** @var list<string> */
    private array $outletIds = [];

    private bool $bypassScope = false;

    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->tenantId = (string) $tenant->getKey();
    }

    public function setTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
        if ($this->tenant !== null && (string) $this->tenant->getKey() !== $tenantId) {
            $this->tenant = null;
        }
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function getTenant(): ?Tenant
    {
        if ($this->tenant === null && $this->tenantId !== null) {
            $this->tenant = Tenant::query()->find($this->tenantId);
        }

        return $this->tenant;
    }

    /**
     * @param list<string> $outletIds
     */
    public function setOutletIds(array $outletIds): void
    {
        $this->outletIds = array_values(array_unique($outletIds));
    }

    /**
     * @return list<string>
     */
    public function getOutletIds(): array
    {
        return $this->outletIds;
    }

    public function hasOutletRestriction(): bool
    {
        return $this->outletIds !== [];
    }

    public function isBypassingScope(): bool
    {
        return $this->bypassScope;
    }

    /**
     * Execute a callback within the context of a specific tenant.
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function runForTenant(Tenant|string $tenant, Closure $callback): mixed
    {
        $previousTenantId = $this->tenantId;
        $previousTenant = $this->tenant;

        try {
            if ($tenant instanceof Tenant) {
                $this->setTenant($tenant);
            } else {
                $this->setTenantId($tenant);
            }

            return $callback();
        } finally {
            $this->tenantId = $previousTenantId;
            $this->tenant = $previousTenant;
        }
    }

    /**
     * Execute a callback bypassing tenant scoping (strictly for platform super-admin or global jobs).
     *
     * @template TReturn
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function withoutTenantScope(Closure $callback): mixed
    {
        $previous = $this->bypassScope;
        $this->bypassScope = true;

        try {
            return $callback();
        } finally {
            $this->bypassScope = $previous;
        }
    }
}
