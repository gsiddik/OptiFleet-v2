<?php

namespace App\Domain\Vehicle\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleDocument extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'document_type', 'document_number',
        'issue_date', 'expiry_date', 'disk', 'path', 'original_filename',
        'mime_type', 'size', 'notes', 'uploaded_by', 'has_expiry', 'needs_extension', 'extension_deadline',
    ];

    public const TYPES = ['REGISTRATION', 'INSPECTION_CERTIFICATE', 'INSURANCE', 'PERMIT', 'VEHICLE_TAX', 'WARRANTY', 'OTHER'];

    protected $hidden = ['path', 'disk'];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'extension_deadline' => 'date',
        'has_expiry' => 'boolean',
        'needs_extension' => 'boolean',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
