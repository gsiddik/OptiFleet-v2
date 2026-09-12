<?php

namespace App\Domain\QualityControl\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcFinding extends Model
{
    use HasUuids;

    protected $fillable = ['qc_inspection_id', 'description', 'severity', 'resolved', 'resolved_by', 'resolved_at'];

    protected $casts = ['resolved' => 'boolean', 'resolved_at' => 'datetime'];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }
}
