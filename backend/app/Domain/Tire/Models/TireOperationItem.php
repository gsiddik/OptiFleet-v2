<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One position of a tire operation (with its replacement serial, rotation pair or measurement). */
class TireOperationItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'tire_operation_id', 'position_code', 'tire_id', 'replacement_tire_id', 'pair_number', 'tread_depth_mm',
        'applied_at', 'replacement_released_at',
    ];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'replacement_released_at' => 'datetime', 'tread_depth_mm' => 'decimal:2', 'pair_number' => 'integer'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TireOperation::class, 'tire_operation_id');
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function replacementTire(): BelongsTo
    {
        return $this->belongsTo(Tire::class, 'replacement_tire_id');
    }
}
