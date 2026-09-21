<?php

namespace App\Domain\ProductMaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductToolSpec extends Model
{
    protected $table = 'product_tools';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'model', 'tool_type_id', 'specification',
        'checkout_required', 'calibration_required', 'calibration_interval_value', 'calibration_interval_unit',
        'maintenance_required', 'maintenance_interval_value', 'maintenance_interval_unit',
    ];

    protected function casts(): array
    {
        return [
            'checkout_required' => 'boolean',
            'calibration_required' => 'boolean',
            'maintenance_required' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function toolType(): BelongsTo
    {
        return $this->belongsTo(ToolType::class);
    }
}
