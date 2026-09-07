<?php

namespace App\Domain\AccessControl\Models;

use App\Domain\Identity\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataScopeAssignment extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'scope_type',
        'scope_resource_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
