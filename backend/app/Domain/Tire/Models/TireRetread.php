<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Phase E (G-37): auditable — every governance field change (receive/inspect/approve) is recorded. */
class TireRetread extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'tire_id', 'cycle_number', 'sent_at', 'sent_by', 'received_at', 'received_by',
        'partner_id', 'cost', 'notes', 'status',
        'final_inspected_by', 'final_inspected_at', 'final_inspection_result', 'final_inspection_notes',
        'approved_by', 'approved_at', 'approval_disposition', 'approval_reason', 'tire_used_inspection_id', 'final_status',
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

    /** Photos taken when the cycle was opened. */
    public function photos(): HasMany
    {
        return $this->hasMany(TireCyclePhoto::class, 'cycle_id')->where('cycle_type', 'RETREAD')->orderBy('created_at');
    }

    /** The Tire Inspection that re-inspected the tire after the cycle. */
    public function usedInspection(): BelongsTo
    {
        return $this->belongsTo(TireUsedInspection::class, 'tire_used_inspection_id');
    }
}
