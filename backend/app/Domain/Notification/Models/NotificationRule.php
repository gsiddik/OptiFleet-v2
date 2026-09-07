<?php

namespace App\Domain\Notification\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class NotificationRule extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'event_code', 'name', 'is_active', 'is_system',
        'condition_set', 'recipient_rules', 'channels', 'escalation',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
            'condition_set' => 'array',
            'recipient_rules' => 'array',
            'channels' => 'array',
            'escalation' => 'array',
        ];
    }
}
