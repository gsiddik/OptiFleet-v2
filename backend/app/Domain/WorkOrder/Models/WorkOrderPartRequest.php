<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderPartRequest extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUSES = ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'];

    protected $fillable = [
        'tenant_id', 'work_order_id', 'notes', 'status',
        'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = ['requested_at' => 'datetime', 'decided_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderPartRequestItem::class, 'part_request_id');
    }
}
