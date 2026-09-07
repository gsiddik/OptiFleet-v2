<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireRotation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'tire_id', 'vehicle_id', 'from_position', 'to_position', 'odometer', 'work_order_id', 'performed_by', 'occurred_at'];

    protected function casts(): array
    {
        return ['odometer' => 'decimal:2', 'occurred_at' => 'datetime'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
