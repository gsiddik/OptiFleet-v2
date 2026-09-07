<?php

namespace App\Domain\Inspection\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InspectionResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'inspection_id', 'inspection_template_item_id', 'value_text',
        'value_number', 'value_bool', 'passed', 'photo_path',
    ];

    protected $casts = [
        'value_number' => 'decimal:2',
        'value_bool' => 'boolean',
        'passed' => 'boolean',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(InspectionTemplateItem::class, 'inspection_template_item_id');
    }
}
