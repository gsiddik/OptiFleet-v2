<?php

namespace App\Domain\Notification\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationDeliveryLog extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUS_QUEUED = 'QUEUED';

    public const STATUS_SENT = 'SENT';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'tenant_id', 'notification_rule_id', 'event_code', 'resource_type', 'resource_id',
        'recipient_user_id', 'recipient_email', 'channel', 'template_configuration_version_id',
        'status', 'failure_reason', 'queued_at', 'sent_at', 'delivered_at', 'failed_at', 'escalated_at',
    ];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime',
            'failed_at' => 'datetime', 'escalated_at' => 'datetime',
        ];
    }
}
