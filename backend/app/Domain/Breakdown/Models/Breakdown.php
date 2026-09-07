<?php

namespace App\Domain\Breakdown\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Branch;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Breakdown extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'branch_id', 'vehicle_id', 'reported_by', 'reported_at',
        'location', 'severity', 'description', 'evidence', 'response_notes',
        'downtime_start_at', 'status', 'maintenance_request_id', 'work_order_id', 'resolved_at',
    ];

    protected $casts = [
        'reported_at' => 'datetime',
        'downtime_start_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
