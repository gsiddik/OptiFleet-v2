<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowApprovalStep extends Model
{
    use Auditable, HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_SKIPPED = 'SKIPPED';

    protected $fillable = [
        'approval_request_id', 'step_number', 'approver_type', 'approver_identifier',
        'status', 'decided_by', 'decided_at', 'note',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(WorkflowApprovalRequest::class, 'approval_request_id');
    }
}
