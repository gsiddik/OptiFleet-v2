<?php

namespace App\Domain\ProductCatalog\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BundleVersion extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'bundle_id',
        'version_number',
        'status',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'bundle_version_modules')->withPivot('module_code');
    }
}
