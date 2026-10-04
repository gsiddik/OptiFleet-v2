<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A photo taken when a retread / repair cycle is opened (private disk; served through the API). */
class TireCyclePhoto extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'cycle_type', 'cycle_id', 'disk', 'path', 'original_filename', 'mime_type', 'size', 'uploaded_by'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }
}
