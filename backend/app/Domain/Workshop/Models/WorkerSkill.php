<?php

namespace App\Domain\Workshop\Models;

use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerSkill extends Model
{
    use HasUuids;

    protected $fillable = ['worker_id', 'component_group_id', 'skill_level', 'notes'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class)->withTrashed(); // soft-deleted groups stay resolvable on history
    }
}
