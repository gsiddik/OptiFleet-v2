<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Used tire inspection thresholds for a tire category (+ optional tire product = brand/model,
 * + optional application). Every change bumps `version`; inspections keep the version and a
 * snapshot of the thresholds they were evaluated with.
 */
class TireRuleProfile extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const CATEGORIES = ['PASSENGER_LT', 'TRUCK_BUS', 'OTR'];

    protected $fillable = [
        'tenant_id', 'name', 'tire_category', 'product_id', 'application',
        'd_service_mm', 'd_pull_mm', 'a_max_months', 'a_retread_max_months', 'n_retread_max',
        'repair_limits', 'application_limits', 'version', 'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'd_service_mm' => 'decimal:2', 'd_pull_mm' => 'decimal:2',
            'a_max_months' => 'integer', 'a_retread_max_months' => 'integer', 'n_retread_max' => 'integer',
            'repair_limits' => 'array', 'application_limits' => 'array', 'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The thresholds an inspection stores with its result. */
    public function snapshot(): array
    {
        return [
            'profile_id' => $this->id, 'name' => $this->name, 'version' => $this->version,
            'tire_category' => $this->tire_category, 'product_id' => $this->product_id, 'application' => $this->application,
            'd_service_mm' => (string) $this->d_service_mm, 'd_pull_mm' => (string) $this->d_pull_mm,
            'a_max_months' => $this->a_max_months, 'a_retread_max_months' => $this->a_retread_max_months, 'n_retread_max' => $this->n_retread_max,
            'repair_limits' => $this->repair_limits ?? [], 'application_limits' => $this->application_limits ?? [],
        ];
    }
}
