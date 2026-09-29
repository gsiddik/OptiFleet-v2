<?php

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * L3 Subcategory / Component Family beneath a Category (e.g. Disc Brake ->
 * Brake Pad), optionally restricted to a set of Item Types. An empty
 * Item Type set means "unrestricted".
 */
class ComponentSubcategory extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    public const ITEM_TYPES = ['SPARE_PART', 'CONSUMABLE', 'TIRE', 'RIM', 'TOOL', 'EQUIPMENT'];

    protected $fillable = [
        'tenant_id',
        'component_category_id',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ComponentCategory::class, 'component_category_id')->withTrashed();
    }

    /** @return array<int, string> */
    public function itemTypes(): array
    {
        return DB::table('component_subcategory_item_types')
            ->where('component_subcategory_id', $this->id)
            ->orderBy('item_type')
            ->pluck('item_type')
            ->all();
    }

    public function syncItemTypes(array $itemTypes): void
    {
        $itemTypes = array_values(array_unique($itemTypes));
        DB::table('component_subcategory_item_types')
            ->where('component_subcategory_id', $this->id)
            ->whereNotIn('item_type', $itemTypes)
            ->delete();
        $existing = DB::table('component_subcategory_item_types')->where('component_subcategory_id', $this->id)->pluck('item_type')->all();
        $rows = array_map(
            fn ($type) => ['component_subcategory_id' => $this->id, 'item_type' => $type, 'created_at' => now(), 'updated_at' => now()],
            array_values(array_diff($itemTypes, $existing))
        );
        if ($rows !== []) {
            DB::table('component_subcategory_item_types')->insert($rows);
        }
    }

    public function allowsItemType(string $itemType): bool
    {
        $allowed = $this->itemTypes();

        return $allowed === [] || in_array($itemType, $allowed, true);
    }

    /** Self, Category and Component Group all ACTIVE and not soft-deleted. */
    public function scopeEffectivelyActive(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.status", 'ACTIVE')
            ->whereExists(fn ($q) => $q->from('component_categories as ec')
                ->join('component_groups as eg', 'eg.id', '=', 'ec.component_group_id')
                ->whereColumn('ec.id', "{$table}.component_category_id")
                ->whereNull('ec.deleted_at')->where('ec.status', 'ACTIVE')
                ->whereNull('eg.deleted_at')->where('eg.status', 'ACTIVE'));
    }

    /** Restrict to subcategories usable for the Item Type (mapped to it, or unrestricted). */
    public function scopeForItemType(Builder $query, string $itemType): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(fn ($q) => $q
            ->whereExists(fn ($s) => $s->from('component_subcategory_item_types as it')->whereColumn('it.component_subcategory_id', "{$table}.id")->where('it.item_type', $itemType))
            ->orWhereNotExists(fn ($s) => $s->from('component_subcategory_item_types as it2')->whereColumn('it2.component_subcategory_id', "{$table}.id")));
    }

    public function scopeWithUsage(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->selectRaw("EXISTS (SELECT 1 FROM products p WHERE p.component_subcategory_id = {$table}.id) AS is_used");
    }

    public function isUsedByProducts(): bool
    {
        return DB::table('products')->where('component_subcategory_id', $this->id)->exists();
    }
}
