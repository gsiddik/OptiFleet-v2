<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Used Tire Management inspection of a REMOVED / HOLD tire: questionnaire answers, tread
 * measurements, damages, evidence, the rule profile version + thresholds applied, the decision
 * (recommendation + reasons) and its approval (final disposition applied to the tire).
 */
class TireUsedInspection extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const SUBMITTED = 'SUBMITTED';

    public const APPROVED = 'APPROVED';

    public const CANCELLED = 'CANCELLED';

    /** Questionnaire columns. */
    public const ANSWERS = [
        'identity_status', 'internal_inspected', 'wear_pattern', 'bulge_separation', 'cord_exposure', 'sidewall_condition',
        'bead_condition', 'inner_liner_condition', 'run_flat_overheat', 'leak_foreign_object', 'previous_repair', 'age_chemical',
        'casing_compliance', 'repair_eligibility', 'specialist_result',
    ];

    protected $fillable = [
        'tenant_id', 'tire_id', 'status', 'tire_status_before', 'inspected_by', 'inspected_at', 'tire_category', 'application',
        'rule_profile_id', 'rule_profile_version', 'thresholds', 'tire_snapshot',
        'identity_status', 'internal_inspected', 'wear_pattern', 'bulge_separation', 'cord_exposure', 'sidewall_condition',
        'bead_condition', 'inner_liner_condition', 'run_flat_overheat', 'leak_foreign_object', 'previous_repair', 'age_chemical',
        'casing_compliance', 'repair_eligibility', 'specialist_result',
        'd_min_mm', 'd_new_mm', 'remaining_tread_percent',
        'recommendation', 'recommendation_detail', 'additional_work', 'reasons', 'reason_codes', 'follow_ups', 'follow_up_codes', 'variables', 'notes',
        'final_disposition', 'return_warehouse_id', 'approved_by', 'approved_at', 'approval_note', 'cancelled_by', 'cancelled_at',
        'tire_inspection_id',
    ];

    protected function casts(): array
    {
        return [
            'inspected_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime',
            'thresholds' => 'array', 'tire_snapshot' => 'array', 'reasons' => 'array', 'follow_ups' => 'array', 'variables' => 'array',
            'reason_codes' => 'array', 'follow_up_codes' => 'array',
            'd_min_mm' => 'decimal:2', 'd_new_mm' => 'decimal:2', 'remaining_tread_percent' => 'decimal:2',
        ];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function measurements(): HasMany
    {
        return $this->hasMany(TireUsedInspectionMeasurement::class)->orderBy('zone')->orderBy('groove');
    }

    public function damages(): HasMany
    {
        return $this->hasMany(TireUsedInspectionDamage::class)->orderBy('sequence');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(TireUsedInspectionEvidence::class)->orderBy('created_at');
    }
}
