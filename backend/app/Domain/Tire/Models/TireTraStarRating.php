<?php

namespace App\Domain\Tire\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireTraStarRating extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'tra_code_id', 'star_rating', 'purpose'];

    public function traCode(): BelongsTo
    {
        return $this->belongsTo(TireTraCode::class, 'tra_code_id');
    }
}
