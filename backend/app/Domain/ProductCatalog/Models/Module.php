<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'description',
        'category',
        'is_core',
        'is_sellable',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_sellable' => 'boolean',
        ];
    }

    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'module_id',
            'depends_on_module_id'
        )->withTimestamps();
    }

    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'module_dependencies',
            'depends_on_module_id',
            'module_id'
        )->withTimestamps();
    }
}
