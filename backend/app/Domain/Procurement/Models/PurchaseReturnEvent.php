<?php

namespace App\Domain\Procurement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only state history of a Return Order (created, printed, accepted, rejected, received). */
class PurchaseReturnEvent extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_return_id', 'from_status', 'to_status', 'event', 'note', 'performed_by', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
