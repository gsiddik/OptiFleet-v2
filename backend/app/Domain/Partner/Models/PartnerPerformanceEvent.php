<?php

namespace App\Domain\Partner\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPerformanceEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'partner_id', 'event_type', 'reference_type', 'reference_id', 'quantity', 'value', 'occurred_at', 'notes'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'value' => 'decimal:4', 'occurred_at' => 'datetime'];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
