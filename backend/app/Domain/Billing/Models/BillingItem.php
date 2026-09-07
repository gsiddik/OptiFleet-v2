<?php

namespace App\Domain\Billing\Models;

use App\Domain\Contract\Models\ContractItem;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'billing_id',
        'contract_item_id',
        'product_type',
        'product_reference',
        'description',
        'quantity',
        'unit_price',
        'proration_factor',
        'discount',
        'tax',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'proration_factor' => 'decimal:4',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }
}
