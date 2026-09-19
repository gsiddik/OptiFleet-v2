<?php

namespace App\Domain\MaintenanceRequest\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Initial Assessment & Visual Inspection — mutable only while the parent
 * request is DRAFT. Once submitted it is an immutable historical snapshot;
 * enforcement lives in MaintenanceRequestAssessmentService, not here.
 */
class MaintenanceRequestAssessment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'maintenance_request_id', 'assessed_by', 'assessed_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['assessed_at' => 'datetime'];
    }

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(MaintenanceRequestInspectionGroup::class);
    }
}
