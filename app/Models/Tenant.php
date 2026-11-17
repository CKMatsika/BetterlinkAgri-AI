<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RecommendationScope;
use App\Enums\TenantType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'tenant_type',
        'recommendation_scope',
        'whatsapp_phone_number_id',
        'whatsapp_business_account_id',
        'whatsapp_access_token_encrypted',
        'usd_to_zig_rate',
        'persona_config',
        'boundaries_config',
        'outlet_ranking_weights',
        'crm_config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'tenant_type' => TenantType::class,
            'recommendation_scope' => RecommendationScope::class,
            'whatsapp_access_token_encrypted' => 'encrypted',
            'usd_to_zig_rate' => 'decimal:4',
            'persona_config' => 'array',
            'boundaries_config' => 'array',
            'outlet_ranking_weights' => 'array',
            'crm_config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function highConfidenceThreshold(): float
    {
        return (float) ($this->boundaries_config['high_confidence_threshold']
            ?? config('betterlink.diagnosis.high_confidence_threshold', 0.80));
    }

    public function minConfidenceThreshold(): float
    {
        return (float) ($this->boundaries_config['min_confidence_threshold']
            ?? config('betterlink.diagnosis.min_confidence_threshold', 0.55));
    }

    public function maxClarifyingQuestions(): int
    {
        return (int) ($this->boundaries_config['max_clarifying_questions']
            ?? config('betterlink.diagnosis.max_clarifying_questions', 5));
    }

    public function stockFreshnessLimitHours(): int
    {
        return (int) ($this->boundaries_config['stock_freshness_hours']
            ?? config('betterlink.outlets.stock_freshness_limit_hours', 48));
    }

    public function outletSlaMinutes(): int
    {
        return (int) ($this->boundaries_config['outlet_sla_minutes']
            ?? config('betterlink.outlets.reservation_sla_minutes', 30));
    }

    public function repSlaMinutes(): int
    {
        return (int) ($this->boundaries_config['rep_sla_minutes']
            ?? config('betterlink.slas.lead_rep_sla_minutes', 120));
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function inboundSupplyLinks(): HasMany
    {
        return $this->hasMany(TenantSupplyLink::class, 'distributor_or_retailer_tenant_id');
    }

    public function outboundSupplyLinks(): HasMany
    {
        return $this->hasMany(TenantSupplyLink::class, 'manufacturer_tenant_id');
    }
}
