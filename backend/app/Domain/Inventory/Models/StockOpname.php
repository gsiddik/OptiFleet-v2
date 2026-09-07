<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'warehouse_id', 'opname_number', 'status', 'created_by', 'approved_by', 'posted_at', 'notes'];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }
}
