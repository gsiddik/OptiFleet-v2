<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tire extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'serial_number', 'product_id', 'manufacturer', 'manufacture_date_code', 'tire_size', 'pattern',
        'section_width_mm', 'aspect_ratio', 'rim_diameter_inch', 'load_index', 'speed_rating', 'ply_rating',
        'purchase_date', 'purchase_cost', 'warranty_months', 'warranty_km',
        'current_status', 'current_vehicle_id', 'current_position', 'current_warehouse_id',
    ];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'purchase_cost' => 'decimal:4', 'rim_diameter_inch' => 'decimal:1'];
    }

    /** G-26: trims every write path (not just the request layer) so the raw column never carries leading/trailing whitespace. */
    protected function setSerialNumberAttribute(string $value): void
    {
        $this->attributes['serial_number'] = trim($value);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function currentVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'current_vehicle_id');
    }

    public function currentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'current_warehouse_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(TireInstallation::class);
    }

    public function rotations(): HasMany
    {
        return $this->hasMany(TireRotation::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(TireInspection::class);
    }

    public function removals(): HasMany
    {
        return $this->hasMany(TireRemoval::class);
    }

    public function retreads(): HasMany
    {
        return $this->hasMany(TireRetread::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(TireRepair::class);
    }

    public function scoringResults(): HasMany
    {
        return $this->hasMany(TireScoringResult::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(TireSale::class);
    }
}
