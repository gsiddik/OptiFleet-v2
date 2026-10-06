<?php

namespace App\Domain\DocumentGeneration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One generated printed document (PRINT_LOCALE_SNAPSHOT). Immutable: a reprint renders this row's
 * locale and template version; generating again creates a new row and never changes history.
 * Updates are also refused by a database trigger.
 */
class DocumentGeneration extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Microseconds are kept: the generations of one document are ordered by generated_at. */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'tenant_id', 'document_type', 'source_entity_type', 'source_entity_id', 'recipient_partner_id',
        'locale', 'template_id', 'template_version_id', 'template_version', 'generated_at', 'generated_by',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime', 'template_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Document generation records are immutable.'));
        static::deleting(fn () => throw new LogicException('Document generation records are immutable.'));
    }
}
