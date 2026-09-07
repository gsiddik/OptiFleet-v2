<?php

namespace App\Domain\Contract\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractAmendmentItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'contract_amendment_id',
        'action',
        'product_type',
        'product_reference',
        'description',
        'quantity',
        'unit_price',
        'final_amount',
        'contract_item_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'final_amount' => 'decimal:2',
        ];
    }

    public function amendment(): BelongsTo
    {
        return $this->belongsTo(ContractAmendment::class, 'contract_amendment_id');
    }

    public function contractItem(): BelongsTo
    {
        return $this->belongsTo(ContractItem::class);
    }
}
