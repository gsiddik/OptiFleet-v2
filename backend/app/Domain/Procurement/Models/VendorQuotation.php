<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorQuotation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'rfq_id', 'partner_id', 'quotation_number', 'validity_date', 'lead_time_days',
        'payment_terms', 'freight_cost', 'subtotal', 'tax_total', 'total', 'status', 'submitted_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'validity_date' => 'date',
            'freight_cost' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'submitted_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorQuotationItem::class);
    }
}
