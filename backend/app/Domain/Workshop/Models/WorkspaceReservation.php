<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceReservation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** Requested, awaiting approval. */
    public const RESERVED = 'RESERVED';

    public const APPROVED = 'APPROVED';

    /** Legacy (pre-approval model) — treated as approved. */
    public const ACTIVE = 'ACTIVE';

    public const TRANSFERRED = 'TRANSFERRED';

    public const COMPLETED = 'COMPLETED';

    public const CANCELLED = 'CANCELLED';

    /** The Work Order's current assignment (at most one — partial unique index). */
    public const CURRENT = [self::RESERVED, self::APPROVED, self::ACTIVE];

    public const APPROVED_STATES = [self::APPROVED, self::ACTIVE];

    protected $fillable = [
        'tenant_id', 'workspace_id', 'work_order_id', 'start_at', 'end_at', 'status', 'created_by',
        'approved_by', 'approved_at', 'transferred_from_id', 'transferred_by', 'transferred_at', 'completed_at', 'cancelled_at',
    ];

    protected $casts = [
        'start_at' => 'datetime', 'end_at' => 'datetime', 'approved_at' => 'datetime', 'transferred_at' => 'datetime',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transferrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_id');
    }
}
