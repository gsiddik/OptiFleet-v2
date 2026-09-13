<?php

namespace App\Domain\Integration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * R1 §10: the accounting-integration boundary. A row here is written in
 * the SAME database transaction as the domain event it describes
 * (WorkshopInvoiceService writes these), never dispatched or claimed to be
 * delivered by this codebase — no accounting connector exists yet. See
 * IntegrationOutboxService.
 */
class IntegrationOutboxEvent extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUSES = ['PENDING', 'DELIVERED', 'FAILED'];

    protected $fillable = [
        'tenant_id', 'event_type', 'aggregate_type', 'aggregate_id', 'schema_version',
        'correlation', 'payload', 'status', 'attempts', 'last_attempted_at', 'delivered_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'correlation' => 'array',
            'payload' => 'array',
            'last_attempted_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }
}
