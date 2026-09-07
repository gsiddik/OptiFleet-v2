<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rfq extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'rfq_number', 'numbering_configuration_version_id', 'purchase_request_id', 'warehouse_id', 'issue_date', 'response_deadline', 'status', 'notes'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'response_deadline' => 'date'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(\App\Domain\Partner\Models\Partner::class, 'rfq_vendors')->withPivot('invited_at')->withTimestamps();
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(VendorQuotation::class);
    }
}
