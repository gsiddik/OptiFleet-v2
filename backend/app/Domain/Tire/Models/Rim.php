<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rim extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'brand', 'material',
        'width_inch', 'diameter_inch', 'disc_thickness_mm', 'offset_mm',
        'bolt_holes', 'bolt_diameter_mm', 'pcd_mm', 'hub_hole_diameter_mm', 'status',
    ];

    protected function casts(): array
    {
        return [
            'width_inch' => 'decimal:2',
            'diameter_inch' => 'decimal:2',
            'disc_thickness_mm' => 'decimal:2',
            'offset_mm' => 'decimal:2',
            'bolt_diameter_mm' => 'decimal:2',
            'pcd_mm' => 'decimal:2',
            'hub_hole_diameter_mm' => 'decimal:2',
        ];
    }
}
