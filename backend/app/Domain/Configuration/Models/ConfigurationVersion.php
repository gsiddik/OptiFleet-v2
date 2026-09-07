<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationVersion extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'configuration_set_id', 'version_number', 'status', 'payload', 'change_summary', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function configurationSet(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSet::class);
    }
}
