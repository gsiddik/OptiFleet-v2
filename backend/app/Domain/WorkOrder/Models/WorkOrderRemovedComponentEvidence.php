<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderRemovedComponentEvidence extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_removed_component_id', 'disk', 'path',
        'original_filename', 'mime_type', 'size', 'uploaded_by',
    ];

    public function removedComponent(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRemovedComponent::class, 'work_order_removed_component_id');
    }
}
