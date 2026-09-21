<?php

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleBrand extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'name', 'logo_url', 'logo_disk', 'logo_path', 'logo_mime_type', 'logo_size',
        'usage_type', 'usage_types', 'is_system', 'status',
    ];

    protected $hidden = ['logo_disk', 'logo_path'];

    protected $appends = ['logo_available'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'usage_types' => 'array'];
    }

    public function models(): HasMany
    {
        return $this->hasMany(VehicleModel::class);
    }

    public function getLogoAvailableAttribute(): bool
    {
        return $this->logo_path !== null;
    }
}
