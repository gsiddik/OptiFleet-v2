<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase G (G-04): `status` gates Work Order closure — see WorkOrderClosureGuardService. */
class WorkOrderFinding extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_order_id', 'component_group_id', 'severity', 'description',
        'status', 'resolution_notes', 'resolved_by', 'resolved_at', 'created_by',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
