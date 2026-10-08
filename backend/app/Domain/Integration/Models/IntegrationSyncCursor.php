<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationSyncCursor extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'stream', 'cursor', 'last_synced_at', 'last_error'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }
}
