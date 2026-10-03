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

    public const STATUSES = ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED', 'ISSUED'];

    /**
     * The explicit lifecycle: Work Order "Reserve" creates REQUESTED; only REQUESTED can be
     * decided; only APPROVED can be issued. REJECTED / CANCELLED / ISSUED are final.
     */
    public const TRANSITIONS = [
        'REQUESTED' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['ISSUED'],
        'REJECTED' => [],
        'CANCELLED' => [],
        'ISSUED' => [],
    ];

    protected $fillable = [
        'tenant_id', 'work_order_id', 'tire_operation_id', 'notes', 'status',
        'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
        'warehouse_id', 'issued_by', 'issued_at',
    ];

    protected $casts = ['requested_at' => 'datetime', 'decided_at' => 'datetime', 'issued_at' => 'datetime'];

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Organization\Models\Warehouse::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderPartRequestItem::class, 'part_request_id');
    }
}
