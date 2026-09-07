<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleDependency extends Model
{
    use HasUuids;

    protected $fillable = [
        'module_id',
        'depends_on_module_id',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'module_id');
    }

    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Module::class, 'depends_on_module_id');
    }
}
