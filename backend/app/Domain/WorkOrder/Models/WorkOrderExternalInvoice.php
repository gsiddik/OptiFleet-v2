<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice": the External Work Order Invoice — created exactly once per
 * Work Order the first time it is finalized as EXTERNAL (never
 * duplicated across revise/re-finalize cycles), and reused for the whole
 * New External WO -> Delivered -> In Progress -> Cancelled/Billed -> Paid
 * lifecycle.
 *
 * NOT the WorkshopInvoice model (that is the pre-existing, unrelated R1
 * feature: an externally-issued invoice OptiFleet records for a
 * Partner-performed towing/3rd-party service on an otherwise INTERNAL
 * Work Order). Deliberately named/tabled differently to avoid colliding
 * with that already-shipped feature's routes, permissions, and model.
 *
 * History is not a bespoke table here — `Auditable` already records every
 * column change (old/new values) to the generic, immutable `audit_logs`
 * table, which is exactly what "View History" needs.
 */
class WorkOrderExternalInvoice extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUSES = ['NEW_EXTERNAL_WO', 'DELIVERED', 'IN_PROGRESS', 'CANCELLED', 'BILLED', 'PAID'];

    /** Statuses from which Cancel is still a business-safe action (see the action matrix). */
    public const CANCELLABLE_STATUSES = ['NEW_EXTERNAL_WO', 'DELIVERED'];

    public const WORK_AUTHORIZATION_STATUSES = ['NOT_GENERATED', 'GENERATED', 'ACKNOWLEDGED'];

    protected $fillable = [
        'tenant_id', 'branch_id', 'work_order_id', 'status', 'work_authorization_status',
        'wal_number', 'wal_issue_date', 'wal_workshop_partner_id', 'wal_workshop_name', 'wal_workshop_address',
        'wal_workshop_pic', 'wal_workshop_phone', 'wal_vehicle_unit_number', 'wal_vehicle_registration_number',
        'wal_vehicle_make_model', 'wal_vehicle_odometer', 'wal_company_name', 'wal_revision', 'wal_generated_by', 'wal_generated_at',
        'delivered_by', 'delivered_at', 'acknowledged_by', 'acknowledged_at',
        'vendor_invoice_date', 'vendor_invoice_amount', 'payment_term', 'completed_by', 'completed_at',
        'payment_date', 'paid_amount', 'settled_by', 'settled_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'wal_issue_date' => 'date',
            'wal_vehicle_odometer' => 'decimal:2',
            'wal_generated_at' => 'datetime',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'vendor_invoice_date' => 'date',
            'vendor_invoice_amount' => 'decimal:4',
            'completed_at' => 'datetime',
            'payment_date' => 'date',
            'paid_amount' => 'decimal:4',
            'settled_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function walWorkshopPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'wal_workshop_partner_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(WorkOrderExternalInvoiceFile::class, 'external_invoice_id');
    }

    public function acknowledgementFile(): HasOne
    {
        return $this->hasOne(WorkOrderExternalInvoiceFile::class, 'external_invoice_id')->where('file_role', 'ACKNOWLEDGEMENT');
    }

    public function completedWorkOrderFile(): HasOne
    {
        return $this->hasOne(WorkOrderExternalInvoiceFile::class, 'external_invoice_id')->where('file_role', 'COMPLETED_WORK_ORDER');
    }

    public function vendorInvoiceFile(): HasOne
    {
        return $this->hasOne(WorkOrderExternalInvoiceFile::class, 'external_invoice_id')->where('file_role', 'VENDOR_INVOICE');
    }

    public function paymentProofFile(): HasOne
    {
        return $this->hasOne(WorkOrderExternalInvoiceFile::class, 'external_invoice_id')->where('file_role', 'PAYMENT_PROOF');
    }
}
