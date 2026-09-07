<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowApprovalRequest extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'tenant_id', 'resource_type', 'resource_id', 'workflow_configuration_version_id',
        'transition_action_code', 'from_status', 'to_status', 'requested_by', 'status', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowApprovalStep::class, 'approval_request_id')->orderBy('step_number');
    }
}
