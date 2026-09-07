<?php

namespace App\Domain\Entitlement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleEntitlement extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'tenant_id',
        'module_id',
        'active',
        'valid_from',
        'valid_until',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->active) {
            return false;
        }
        $today = now()->toDateString();
        if ($this->valid_from && $this->valid_from->toDateString() > $today) {
            return false;
        }
        if ($this->valid_until && $this->valid_until->toDateString() < $today) {
            return false;
        }

        return true;
    }
}
