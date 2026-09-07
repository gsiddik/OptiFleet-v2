<?php

namespace App\Domain\ProductCatalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bundle extends Model
{
    use Auditable, HasUuids, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'description',
        'status',
        'is_active',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'bundle_modules')->withTimestamps();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BundleVersion::class);
    }

    public function latestVersion(): ?BundleVersion
    {
        return $this->versions()->orderByDesc('version_number')->first();
    }

    public function isPublished(): bool
    {
        return $this->status === 'PUBLISHED';
    }
}
