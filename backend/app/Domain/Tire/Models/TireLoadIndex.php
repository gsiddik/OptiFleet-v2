<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TireLoadIndex extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'max_load_single_kg', 'max_load_dual_kg', 'is_system', 'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'max_load_single_kg' => 'decimal:2',
            'max_load_dual_kg' => 'decimal:2',
        ];
    }
}
