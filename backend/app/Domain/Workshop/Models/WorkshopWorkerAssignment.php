<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopWorkerAssignment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'worker_id', 'from_branch_id', 'to_branch_id',
        'from_workshop_id', 'to_workshop_id', 'effective_from', 'effective_until', 'assigned_by',
    ];

    protected $casts = ['effective_from' => 'date', 'effective_until' => 'date'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
