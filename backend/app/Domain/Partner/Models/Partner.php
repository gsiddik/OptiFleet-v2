<?php

namespace App\Domain\Partner\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Partner extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    /** Partner types that can be invited to an RFQ / quote for purchased goods. */
    public const RFQ_VENDOR_TYPES = ['SUPPLIER', 'SPARE_PART_SUPPLIER', 'TIRE_SUPPLIER'];

    protected $fillable = [
        'tenant_id', 'code', 'name', 'partner_type', 'contact_name', 'contact_phone',
        'contact_email', 'address', 'province', 'city', 'tax_id', 'payment_terms',
        'bank', 'account_holder', 'account_number', 'description', 'status',
    ];

    public function performanceEvents(): HasMany
    {
        return $this->hasMany(PartnerPerformanceEvent::class);
    }
}
