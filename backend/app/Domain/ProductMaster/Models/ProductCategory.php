<?php

namespace App\Domain\ProductMaster\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'code', 'name', 'parent_id', 'item_type', 'description', 'is_system', 'status', 'requires_specification_grade'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'requires_specification_grade' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ProductCategory::class, 'parent_id');
    }
}
