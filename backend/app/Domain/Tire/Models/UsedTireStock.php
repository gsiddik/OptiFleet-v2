<?php

namespace App\Domain\Tire\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantity on hand of REUSE tires of one tire product in one warehouse (separate from new stock). */
class UsedTireStock extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'warehouse_id', 'product_id', 'quantity_on_hand'];

    protected function casts(): array
    {
        return ['quantity_on_hand' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
