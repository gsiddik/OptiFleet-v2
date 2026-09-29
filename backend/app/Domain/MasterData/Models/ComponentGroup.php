<?php

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class ComponentGroup extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'abbreviation',
        'parent_id',
        'sequence',
        'description',
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

    /**
     * Product usage marker (EXISTS sub-selects, no row counting): a group is
     * "used" once any Product — soft-deleted ones included, their SKUs are
     * still history — references it through the classification pivot or a
     * compatibility rule. Raw sub-selects on purpose: model global scopes
     * (tenant, soft delete) must not hide a reference that makes the
     * abbreviation part of a Product's identity.
     */
    public function scopeWithUsage(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        if ($query->getQuery()->columns === null) {
            $query->select("{$table}.*");
        }

        return $query->selectRaw(
            "(EXISTS (SELECT 1 FROM product_component_groups pcg WHERE pcg.component_group_id = {$table}.id)
              OR EXISTS (SELECT 1 FROM product_compatibilities pc WHERE pc.component_group_id = {$table}.id)) AS is_used"
        );
    }

    public function isUsedByProducts(): bool
    {
        return DB::table('product_component_groups')->where('component_group_id', $this->id)->exists()
            || DB::table('product_compatibilities')->where('component_group_id', $this->id)->exists();
    }

    /**
     * Abbreviation lock: once a group is used by a Product its abbreviation
     * is part of that Product's identity (and of its SKU once SKU generation
     * consumes it) and can no longer change. A missing abbreviation on a
     * legacy group may always be filled in.
     */
    public function isAbbreviationLocked(): bool
    {
        return $this->abbreviation !== null && $this->isUsedByProducts();
    }

    /**
     * Validation rule for selecting a Component Group on NEW data: must be
     * visible to the current tenant (own or platform), not soft-deleted and
     * ACTIVE. `$keep` (one id or a list) lets an edit resend the value(s) it
     * already holds even if that group has since been retired — history stays
     * resolvable.
     */
    public static function selectableRule(string|array|null $keep = null): Exists
    {
        $keepIds = array_values(array_filter((array) $keep));
        $tenantId = app(TenantContext::class)->tenantId();

        return Rule::exists('component_groups', 'id')->where(function ($q) use ($tenantId, $keepIds) {
            $q->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
                ->where(function ($q) use ($keepIds) {
                    $q->where(fn ($q) => $q->whereNull('deleted_at')->where('status', 'ACTIVE'));
                    if ($keepIds !== []) {
                        $q->orWhereIn('id', $keepIds);
                    }
                });
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ComponentGroup::class, 'parent_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(ComponentCategory::class);
    }

    public function vehicleCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleCategory::class,
            'vehicle_category_component_groups'
        )->withTimestamps();
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Domain\ProductMaster\Models\Product::class,
            'product_component_groups'
        )->withTimestamps();
    }
}
