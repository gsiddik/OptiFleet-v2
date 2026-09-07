<?php

namespace App\Domain\Tire\Models;

use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireRetread extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'tire_id', 'cycle_number', 'sent_at', 'received_at', 'partner_id', 'cost', 'notes'];

    protected function casts(): array
    {
        return ['sent_at' => 'date', 'received_at' => 'date', 'cost' => 'decimal:4'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }
}
