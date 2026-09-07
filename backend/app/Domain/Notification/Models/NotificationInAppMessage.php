<?php

namespace App\Domain\Notification\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationInAppMessage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'recipient_user_id', 'event_code', 'subject', 'body', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
