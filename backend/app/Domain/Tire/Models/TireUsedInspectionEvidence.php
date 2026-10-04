<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A photo of a used tire inspection (damage photo or close-up with scale), stored on the private disk. */
class TireUsedInspectionEvidence extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tire_used_inspection_evidence';

    public const KINDS = ['DAMAGE_PHOTO', 'CLOSE_UP_SCALE'];

    protected $fillable = [
        'tenant_id', 'tire_used_inspection_id', 'tire_used_inspection_damage_id', 'kind', 'disk', 'path',
        'original_filename', 'mime_type', 'size', 'notes', 'uploaded_by',
    ];

    protected $hidden = ['disk', 'path'];
}
