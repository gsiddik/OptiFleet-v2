<?php

namespace App\Domain\ProductMaster\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Uom extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'measure_type', 'description', 'is_system', 'status'];

    /** Lets forms offer decimal entry only for measured units (QuantityPolicy). */
    protected $appends = ['allows_fractional_quantity'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    protected function getAllowsFractionalQuantityAttribute(): bool
    {
        return QuantityPolicy::uomAllowsFraction($this);
    }
}
