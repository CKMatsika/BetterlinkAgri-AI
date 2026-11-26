<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SellerVerification extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'organisation_id',
        'outlet_id',
        'status',
        'business_registration_number',
        'id_document_path',
        'outlet_photo_path',
        'verified_address',
        'reviewed_by_user_id',
        'reviewed_at',
        'rejection_or_suspension_reason',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'organisation_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
