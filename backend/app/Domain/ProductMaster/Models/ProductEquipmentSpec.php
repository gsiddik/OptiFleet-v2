<?php

namespace App\Domain\ProductMaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEquipmentSpec extends Model
{
    protected $table = 'product_equipment';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'model', 'equipment_type_id', 'specification',
        'capacity_value', 'capacity_uom_id', 'power_source', 'power_rating_value', 'power_rating_unit', 'voltage_v',
        'maintenance_required', 'maintenance_interval_value', 'maintenance_interval_unit',
        'inspection_required', 'inspection_interval_value', 'inspection_interval_unit',
        'calibration_required', 'calibration_interval_value', 'calibration_interval_unit',
        'certification_required', 'certification_type',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_required' => 'boolean',
            'inspection_required' => 'boolean',
            'calibration_required' => 'boolean',
            'certification_required' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function equipmentType(): BelongsTo
    {
        return $this->belongsTo(EquipmentType::class);
    }

    public function capacityUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'capacity_uom_id');
    }
}
