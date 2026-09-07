<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workspace extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['tenant_id', 'workshop_id', 'code', 'name', 'workspace_type', 'status'];

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function vehicleCategories(): BelongsToMany
    {
        return $this->belongsToMany(VehicleCategory::class, 'workspace_vehicle_categories')->withTimestamps();
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(WorkspaceReservation::class);
    }
}
