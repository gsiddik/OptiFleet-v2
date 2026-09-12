<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConfigurationSet extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    public const TYPE_NUMBERING = 'NUMBERING';

    public const TYPE_TEMPLATE = 'TEMPLATE';

    public const TYPE_WORKFLOW = 'WORKFLOW';

    public const TYPE_NOTIFICATION = 'NOTIFICATION';

    public const TYPE_TIRE_SCORING = 'TIRE_SCORING';

    public const SCOPE_TENANT = 'TENANT';

    public const SCOPE_BRANCH = 'BRANCH';

    public const SCOPE_WORKSHOP = 'WORKSHOP';

    public const SCOPE_WAREHOUSE = 'WAREHOUSE';

    protected $fillable = [
        'tenant_id', 'type', 'code', 'scope_type', 'scope_resource_id', 'name', 'is_system',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ConfigurationVersion::class);
    }

    public function publishedVersion(): ?ConfigurationVersion
    {
        return $this->versions()->where('status', 'PUBLISHED')->first();
    }
}
