<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegrationSyncCursor extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'stream', 'cursor', 'last_synced_at', 'last_error',
        'last_attempted_at', 'last_status', 'last_error_code', 'consecutive_failures', 'last_stats', 'last_reading_received_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime', 'last_attempted_at' => 'datetime', 'last_reading_received_at' => 'datetime',
            'consecutive_failures' => 'integer', 'last_stats' => 'array',
        ];
    }
}
