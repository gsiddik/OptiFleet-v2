<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LEGACY — historical only. Tire Scoring was retired in favour of the Used Tire Inspection engine:
 * no code creates, finalizes or decides anything from these rows any more. They are kept (and
 * readable through Tire::scoringResults / TireSale::scoringResult) so past results stay auditable.
 *
 * Phase F (G-31): one structured scoring calculation.
 */
class TireScoringResult extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'tire_id', 'tire_inspection_id', 'tire_retread_id', 'tire_repair_id',
        'scoring_type', 'configuration_version_id',
        'reference_tread_depth_mm', 'measured_tread_depth_mm', 'spa_raw_percent', 'spa_normalized_score',
        'classification', 'ka_score', 'kf_score', 'critical_safety_fail', 'critical_safety_reasons',
        'eligible_for_operational_reuse', 'computed_by', 'computed_at', 'finalized_by', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'reference_tread_depth_mm' => 'decimal:2', 'measured_tread_depth_mm' => 'decimal:2',
            'spa_raw_percent' => 'decimal:2', 'spa_normalized_score' => 'decimal:2', 'ka_score' => 'decimal:2', 'kf_score' => 'decimal:2',
            'critical_safety_fail' => 'boolean', 'eligible_for_operational_reuse' => 'boolean',
            'computed_at' => 'datetime', 'finalized_at' => 'datetime',
        ];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(TireInspection::class, 'tire_inspection_id');
    }

    public function retread(): BelongsTo
    {
        return $this->belongsTo(TireRetread::class, 'tire_retread_id');
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(TireRepair::class, 'tire_repair_id');
    }

    public function configurationVersion(): BelongsTo
    {
        return $this->belongsTo(ConfigurationVersion::class);
    }
}
