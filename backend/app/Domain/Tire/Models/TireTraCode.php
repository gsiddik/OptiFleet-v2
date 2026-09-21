<?php

namespace App\Domain\Tire\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TireTraCode extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'profile', 'is_system', 'status'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function starRatings(): HasMany
    {
        return $this->hasMany(TireTraStarRating::class, 'tra_code_id');
    }
}
