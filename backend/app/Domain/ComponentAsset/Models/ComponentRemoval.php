<?php

namespace App\Domain\ComponentAsset\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentRemoval extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'component_asset_id', 'component_installation_id', 'removal_odometer', 'removal_reason',
        'condition', 'disposition', 'replaced_by_asset_id', 'diagnosis_note', 'work_order_id', 'removed_by', 'removed_at',
    ];

    protected function casts(): array
    {
        return ['removal_odometer' => 'decimal:2', 'removed_at' => 'datetime'];
    }

    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(ComponentAsset::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(ComponentInstallation::class, 'component_installation_id');
    }

    public function replacedByAsset(): BelongsTo
    {
        return $this->belongsTo(ComponentAsset::class, 'replaced_by_asset_id');
    }
}
