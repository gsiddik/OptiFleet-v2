<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Return Order: goods of a Purchase Order sent back to the vendor (per PO line), for a refund or
 * a redelivery. See PurchaseReturnService for the state machine.
 */
class PurchaseReturn extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const REFUND = 'REFUND';

    public const REDELIVERY = 'REDELIVERY';

    public const OPTIONS = [self::REFUND, self::REDELIVERY];

    public const REFUND_REQUESTED = 'REFUND_REQUESTED';

    public const REFUND_ACCEPTED = 'REFUND_ACCEPTED';

    public const REDELIVERY_REQUESTED = 'REDELIVERY_REQUESTED';

    public const REDELIVERY_READY = 'REDELIVERY_READY';

    public const REDELIVERY_PENDING = 'REDELIVERY_PENDING';

    public const REDELIVERY_RECEIVED = 'REDELIVERY_RECEIVED';

    /** Not final: the PO shows this Return Order's actions instead of "Return to Vendor". */
    public const OPEN = [self::REFUND_REQUESTED, self::REDELIVERY_REQUESTED, self::REDELIVERY_READY, self::REDELIVERY_PENDING];

    /** Waiting for the vendor's redelivery: Goods Receipt is blocked until it is received. */
    public const AWAITING_REDELIVERY = [self::REDELIVERY_REQUESTED, self::REDELIVERY_READY, self::REDELIVERY_PENDING];

    protected $fillable = [
        'tenant_id', 'return_number', 'numbering_configuration_version_id', 'purchase_order_id', 'partner_id', 'warehouse_id',
        'return_option', 'status', 'returned_at', 'refunded_amount', 'vendor_decision', 'vendor_decided_by', 'vendor_decided_at',
        'vendor_decision_note', 'printed_by', 'printed_at', 'redelivery_received_by', 'redelivery_received_at', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'returned_at' => 'datetime', 'vendor_decided_at' => 'datetime', 'printed_at' => 'datetime',
            'redelivery_received_at' => 'datetime', 'refunded_amount' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PurchaseReturnEvent::class)->orderBy('occurred_at')->orderBy('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
