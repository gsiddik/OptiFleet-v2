<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One canonical accounting event with its per-tenant monotonic `sequence`. Append-only (database trigger),
 * written only by AccountingFeedProjector in the transaction that wrote the integration outbox row.
 */
class AccountingFeedEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'sequence', 'event_id', 'event_type', 'occurred_at', 'correlation_id', 'source', 'dimensions', 'payload',
    ];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'occurred_at' => 'datetime', 'source' => 'array', 'dimensions' => 'array', 'payload' => 'array'];
    }
}
