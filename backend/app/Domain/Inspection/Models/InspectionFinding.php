<?php

namespace App\Domain\Inspection\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionFinding extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'inspection_id', 'vehicle_id', 'component_group_id', 'category_id',
        'severity', 'description', 'evidence', 'recommended_action', 'created_by',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class)->withTrashed(); // soft-deleted groups stay resolvable on history
    }
}
