<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceReservation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'workspace_id', 'work_order_id', 'start_at', 'end_at', 'status', 'created_by'];

    protected $casts = ['start_at' => 'datetime', 'end_at' => 'datetime'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
