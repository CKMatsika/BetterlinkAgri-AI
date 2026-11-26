<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Enums\MarketplaceRequestStatus;
use App\Models\DemandAggregate;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\RequestRecipient;
use App\Models\SaleEvent;
use App\Models\StockSignal;
use App\Support\MarketplaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Enforces the Marketplace visibility matrix at the Eloquent query layer.
 * Runs alongside (never replacing or weakening) TenantScope.
 */
final class MarketplaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var MarketplaceContext $ctx */
        $ctx = app(MarketplaceContext::class);

        if (!$ctx->isActive() || $ctx->isBypassing()) {
            return;
        }

        $actorType = $ctx->getActorType();
        $actorId = $ctx->getActorId();
        $orgId = $ctx->getOrganisationId();

        if ($model instanceof MarketplaceRequest) {
            $this->scopeRequests($builder, $model, $actorType, $actorId, $orgId);
            return;
        }

        if ($model instanceof Offer) {
            $this->scopeOffers($builder, $model, $actorType, $actorId, $orgId);
            return;
        }

        if ($model instanceof MarketplaceOrder) {
            $this->scopeOrders($builder, $model, $actorType, $actorId, $orgId);
            return;
        }

        if ($model instanceof RequestRecipient) {
            if ($actorType === 'retailer' && $orgId !== null) {
                $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            } elseif ($actorType === 'farmer' && $actorId !== null) {
                $builder->whereHas('request', fn (Builder $q) => $q->where('farmer_id', '=', $actorId));
            } else {
                $builder->whereRaw('1 = 0');
            }
            return;
        }

        if ($model instanceof DemandAggregate) {
            $this->scopeDemandAggregates($builder, $model, $actorType, $orgId, $ctx->getAssignedDistrictIds());
            return;
        }

        if ($model instanceof SaleEvent || $model instanceof StockSignal) {
            if ($actorType === 'retailer' && $orgId !== null) {
                $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            } elseif ($actorType === 'distributor' || $actorType === 'manufacturer') {
                // Allowed only for own organisation or linked downstream retailers with consent (enforced further in policies/services)
                if ($orgId !== null) {
                    $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
                } else {
                    $builder->whereRaw('1 = 0');
                }
            } else {
                $builder->whereRaw('1 = 0');
            }
        }
    }

    private function scopeRequests(Builder $builder, Model $model, ?string $actorType, ?string $actorId, ?string $orgId): void
    {
        if ($actorType === 'farmer' && $actorId !== null) {
            $builder->where($model->qualifyColumn('farmer_id'), '=', $actorId);
            return;
        }

        if ($actorType === 'retailer' && $orgId !== null) {
            $builder->whereHas('recipients', fn (Builder $q) => $q->where('organisation_id', '=', $orgId));
            return;
        }

        if ($actorType === 'distributor') {
            $builder->where($model->qualifyColumn('status'), '=', MarketplaceRequestStatus::UPSTREAM_ESCALATED->value);
            return;
        }

        // Manufacturers, Agritex (government_office), and Banks never see individual stock requests
        $builder->whereRaw('1 = 0');
    }

    private function scopeOffers(Builder $builder, Model $model, ?string $actorType, ?string $actorId, ?string $orgId): void
    {
        if ($actorType === 'farmer' && $actorId !== null) {
            $builder->whereHas('request', fn (Builder $q) => $q->where('farmer_id', '=', $actorId));
            return;
        }

        // Retailers NEVER see other retailers' offers
        if ($actorType === 'retailer' && $orgId !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            return;
        }

        // Distributors, Manufacturers, Banks, Agritex never see competing retail offers
        $builder->whereRaw('1 = 0');
    }

    private function scopeOrders(Builder $builder, Model $model, ?string $actorType, ?string $actorId, ?string $orgId): void
    {
        if ($actorType === 'farmer' && $actorId !== null) {
            $builder->where($model->qualifyColumn('farmer_id'), '=', $actorId);
            return;
        }

        if ($actorType === 'retailer' && $orgId !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            return;
        }

        $builder->whereRaw('1 = 0');
    }

    /**
     * @param list<string> $assignedDistrictIds
     */
    private function scopeDemandAggregates(Builder $builder, Model $model, ?string $actorType, ?string $orgId, array $assignedDistrictIds): void
    {
        if ($actorType === 'agritex') {
            // Agritex sees only area-wide anonymised cells meeting k >= 5 minimum-group suppression threshold
            $builder->where($model->qualifyColumn('contributing_farmers_count'), '>=', 5);
            if ($assignedDistrictIds !== []) {
                $builder->whereIn($model->qualifyColumn('district_id'), $assignedDistrictIds);
            }
            return;
        }

        if ($actorType === 'retailer' && $orgId !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            return;
        }

        if (($actorType === 'distributor' || $actorType === 'manufacturer') && $orgId !== null) {
            $builder->where($model->qualifyColumn('organisation_id'), '=', $orgId);
            return;
        }

        $builder->whereRaw('1 = 0');
    }
}
