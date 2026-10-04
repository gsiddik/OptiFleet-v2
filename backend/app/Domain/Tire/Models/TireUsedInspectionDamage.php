<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One damage found in a used tire inspection (location, type, dimensions, structure / overlap). */
class TireUsedInspectionDamage extends Model
{
    use BelongsToTenant, HasUuids;

    public const LOCATIONS = ['TREAD', 'SHOULDER', 'SIDEWALL', 'BEAD', 'INNER_LINER'];

    public const TYPES = ['PUNCTURE', 'CUT', 'CRACK', 'ABRASION', 'SEPARATION', 'PREVIOUS_REPAIR_DAMAGE', 'OTHER'];

    protected $fillable = [
        'tenant_id', 'tire_used_inspection_id', 'sequence', 'location', 'damage_type',
        'diameter_mm', 'length_mm', 'width_mm', 'depth_mm', 'reaches_reinforcement', 'overlaps_previous_repair', 'notes',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'diameter_mm' => 'decimal:2', 'length_mm' => 'decimal:2', 'width_mm' => 'decimal:2', 'depth_mm' => 'decimal:2'];
    }
}
