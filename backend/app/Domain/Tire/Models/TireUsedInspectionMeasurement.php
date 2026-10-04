<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One tread depth point (zone 1–3 × main groove inner / outer / center) of a used tire inspection. */
class TireUsedInspectionMeasurement extends Model
{
    use BelongsToTenant, HasUuids;

    public const GROOVES = ['INNER_MAIN', 'OUTER_MAIN', 'CENTER'];

    protected $fillable = ['tenant_id', 'tire_used_inspection_id', 'zone', 'groove', 'depth_mm'];

    protected function casts(): array
    {
        return ['zone' => 'integer', 'depth_mm' => 'decimal:2'];
    }
}
