<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase E (G-27/G-37): distinct REPAIR lifecycle, mirroring TireRetread's governance trail; auditable. */
class TireRepair extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'tire_id', 'cycle_number', 'sent_at', 'sent_by', 'partner_id', 'cost', 'notes',
        'received_at', 'received_by', 'status',
        'final_inspected_by', 'final_inspected_at', 'final_inspection_result', 'final_inspection_notes',
        'approved_by', 'approved_at', 'approval_disposition', 'approval_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'date', 'received_at' => 'date', 'cost' => 'decimal:4',
            'final_inspected_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
