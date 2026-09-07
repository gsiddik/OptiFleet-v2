<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireInspection extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'tire_id', 'tread_depth_mm', 'pressure_psi', 'condition', 'damage', 'recommendation', 'evidence', 'work_order_id', 'inspected_by', 'inspected_at'];

    protected function casts(): array
    {
        return ['tread_depth_mm' => 'decimal:2', 'pressure_psi' => 'decimal:2', 'inspected_at' => 'datetime'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }
}
