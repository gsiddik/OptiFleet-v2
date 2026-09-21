<?php

namespace App\Domain\Tire\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductTireSpec extends Model
{
    protected $table = 'product_tires';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'vehicle_group', 'pattern_name', 'width_mm', 'aspect_ratio_percent',
        'construction_type', 'rim_diameter_inch', 'tire_type',
        'single_load_index_id', 'speed_rating_id', 'dual_load_index_id', 'ply_rating_id', 'tra_code_id', 'tra_star_rating_id',
        'tire_size_computed', 'single_max_load_kg_computed', 'max_speed_kmh_computed',
        'dual_max_load_kg_computed', 'load_range_computed', 'tra_profile_computed', 'purpose_computed',
    ];

    protected function casts(): array
    {
        return [
            'rim_diameter_inch' => 'decimal:1',
            'single_max_load_kg_computed' => 'decimal:2',
            'max_speed_kmh_computed' => 'decimal:2',
            'dual_max_load_kg_computed' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function singleLoadIndex(): BelongsTo
    {
        return $this->belongsTo(TireLoadIndex::class, 'single_load_index_id');
    }

    public function speedRating(): BelongsTo
    {
        return $this->belongsTo(TireSpeedRating::class);
    }

    public function dualLoadIndex(): BelongsTo
    {
        return $this->belongsTo(TireLoadIndex::class, 'dual_load_index_id');
    }

    public function plyRating(): BelongsTo
    {
        return $this->belongsTo(TirePlyRating::class);
    }

    public function traCode(): BelongsTo
    {
        return $this->belongsTo(TireTraCode::class);
    }

    public function traStarRating(): BelongsTo
    {
        return $this->belongsTo(TireTraStarRating::class, 'tra_star_rating_id');
    }
}
