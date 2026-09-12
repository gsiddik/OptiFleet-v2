<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Organization\Models\Workshop;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use Auditable, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'legal_name',
        'industry',
        'tax_id',
        'address',
        'phone',
        'email',
        'website',
        'status',
        'timezone',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['id', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function tenantUsers(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function workshops(): HasMany
    {
        return $this->hasMany(Workshop::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function moduleEntitlements(): HasMany
    {
        return $this->hasMany(TenantModuleEntitlement::class);
    }

    public function capacityLimits(): HasMany
    {
        return $this->hasMany(TenantCapacityLimit::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'ACTIVE';
    }
}
