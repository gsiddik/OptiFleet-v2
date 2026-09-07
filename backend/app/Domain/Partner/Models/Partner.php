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

    protected $fillable = [
        'tenant_id', 'code', 'name', 'partner_type', 'contact_name', 'contact_phone',
        'contact_email', 'address', 'tax_id', 'payment_terms', 'status',
    ];

    public function performanceEvents(): HasMany
    {
        return $this->hasMany(PartnerPerformanceEvent::class);
    }
}
