<?php

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * L2 Category / Assembly beneath a Component Group (e.g. BRK -> Disc Brake).
 * Managed master data, tenant-or-platform owned like ComponentGroup.
 */
class ComponentCategory extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'component_group_id',
        'code',
        'name',
        'description',
        'sequence',
        'is_system',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_used' => 'boolean',
        ];
    }

    /** Parent stays resolvable even after it is soft-deleted (history, admin lists). */
    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class)->withTrashed();
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(ComponentSubcategory::class);
    }

    /**
     * Effective availability for NEW data: this row and its Component Group
     * are both ACTIVE and not soft-deleted. Children are never mutated when a
     * parent is retired — they simply stop being effective.
     */
    public function scopeEffectivelyActive(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.status", 'ACTIVE')
            ->whereExists(fn ($q) => $q->from('component_groups as eg')
                ->whereColumn('eg.id', "{$table}.component_group_id")
                ->whereNull('eg.deleted_at')
                ->where('eg.status', 'ACTIVE'));
    }

    /** Product usage marker; raw sub-select so no scope can hide a reference. */
    public function scopeWithUsage(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->selectRaw("EXISTS (SELECT 1 FROM products p WHERE p.component_category_id = {$table}.id) AS is_used");
    }

    public function isUsedByProducts(): bool
    {
        return DB::table('products')->where('component_category_id', $this->id)->exists();
    }
}
