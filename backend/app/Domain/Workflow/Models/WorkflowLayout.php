<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Where the Visual Workflow Builder draws each status of one tenant workflow version:
 * positions {STATUS_CODE: {x, y}} and an optional viewport. Presentation only — never read by the
 * workflow runtime and kept out of the versioned workflow payload.
 */
class WorkflowLayout extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'configuration_version_id', 'positions', 'viewport', 'updated_by'];

    protected function casts(): array
    {
        return ['positions' => 'array', 'viewport' => 'array'];
    }
}
