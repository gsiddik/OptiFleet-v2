<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An evidence photo attached during Used Sparepart Processing inspection (private file). */
class UsedPartInspectionEvidence extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'used_part_inspection_evidence';

    protected $fillable = [
        'tenant_id', 'work_order_part_return_id', 'disk', 'path',
        'original_filename', 'mime_type', 'size', 'uploaded_by',
    ];

    /** Storage location is never exposed; files are streamed through the authorized endpoint. */
    protected $hidden = ['disk', 'path'];

    public function usedPartReturn(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPartReturn::class, 'work_order_part_return_id');
    }
}
