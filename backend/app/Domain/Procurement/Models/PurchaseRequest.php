<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequest extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'pr_number', 'numbering_configuration_version_id', 'workflow_configuration_version_id', 'branch_id', 'workshop_id', 'warehouse_id', 'source_type', 'source_reference',
        'work_order_id', 'requested_by', 'required_date', 'priority', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['required_date' => 'date'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseRequestItem::class);
    }
}
