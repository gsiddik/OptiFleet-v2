<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase F (BD-5): a tire's terminal sell disposition — see the migration's docblock for the three sell_type values. */
class TireSale extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'tire_id', 'sell_type', 'tire_scoring_result_id', 'tire_used_inspection_id', 'reason', 'sold_by', 'sold_at'];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    /** The approved Used Tire Inspection that justified a sale for operational reuse. */
    public function usedInspection(): BelongsTo
    {
        return $this->belongsTo(TireUsedInspection::class, 'tire_used_inspection_id');
    }

    /** Legacy (historical only): the Tire Scoring result an older sale referenced; Tire Scoring is retired. */
    public function scoringResult(): BelongsTo
    {
        return $this->belongsTo(TireScoringResult::class, 'tire_scoring_result_id');
    }
}
