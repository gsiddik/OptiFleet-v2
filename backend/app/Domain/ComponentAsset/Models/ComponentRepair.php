<?php

namespace App\Domain\ComponentAsset\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentRepair extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'component_asset_id', 'description', 'work_order_id', 'performed_by', 'cost', 'started_at', 'completed_at', 'outcome'];

    protected function casts(): array
    {
        return ['cost' => 'decimal:4', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(ComponentAsset::class);
    }
}
