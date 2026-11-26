<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'district_id',
        'name',
        'email',
        'password',
        'phone_encrypted',
        'whatsapp_number',
        'role',
        'languages_json',
        'availability_schedule_json',
        'is_available_for_handoff',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'phone_encrypted' => 'encrypted',
            'role' => UserRole::class,
            'languages_json' => 'array',
            'availability_schedule_json' => 'array',
            'is_available_for_handoff' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'outlet_users')
            ->withPivot(['role', 'receives_whatsapp_alerts'])
            ->withTimestamps();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->role instanceof UserRole) {
            return false;
        }

        return match ($panel->getId()) {
            'platform' => $this->role->canAccessPlatformPanel(),
            'tenant', 'admin' => $this->role->canAccessTenantPanel(),
            'outlet' => $this->role->canAccessOutletPortal(),
            default => false,
        };
    }

    public function getTenants(Panel $panel): Collection
    {
        if ($this->role === UserRole::SUPER_ADMIN) {
            return Tenant::query()->where('is_active', '=', true)->get();
        }

        return $this->tenant !== null ? collect([$this->tenant]) : collect();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if ($this->role === UserRole::SUPER_ADMIN) {
            return true;
        }

        return $this->tenant_id !== null && $this->tenant_id === $tenant->getKey();
    }
}
