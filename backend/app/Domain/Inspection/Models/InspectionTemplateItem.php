<?php

namespace App\Domain\Inspection\Models;

use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionTemplateItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'inspection_template_id', 'component_group_id', 'item_text', 'input_type',
        'options', 'required', 'sequence', 'threshold', 'status',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplate::class, 'inspection_template_id');
    }

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class);
    }
}
