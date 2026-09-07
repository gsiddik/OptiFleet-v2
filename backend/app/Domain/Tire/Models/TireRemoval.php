<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireRemoval extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'tire_id', 'tire_installation_id', 'removal_odometer', 'removal_reason',
        'condition', 'disposition', 'replaced_by_tire_id', 'work_order_id', 'removed_by', 'removed_at',
    ];

    protected function casts(): array
    {
        return ['removal_odometer' => 'decimal:2', 'removed_at' => 'datetime'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function installation(): BelongsTo
    {
        return $this->belongsTo(TireInstallation::class, 'tire_installation_id');
    }

    public function replacedByTire(): BelongsTo
    {
        return $this->belongsTo(Tire::class, 'replaced_by_tire_id');
    }
}
