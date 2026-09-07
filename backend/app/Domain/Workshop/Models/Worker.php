<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'employee_code', 'name', 'branch_id', 'workshop_id', 'worker_type', 'status', 'user_id'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function skills(): HasMany
    {
        return $this->hasMany(WorkerSkill::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkshopWorkerAssignment::class)->latest('effective_from');
    }

    public function mechanicAssignments(): HasMany
    {
        return $this->hasMany(WorkOrderMechanicAssignment::class);
    }
}
