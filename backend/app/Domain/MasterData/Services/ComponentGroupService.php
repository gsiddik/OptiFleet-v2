<?php

namespace App\Domain\MasterData\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifecycle of Component Group master data, shared by the tenant portal
 * (tenant-owned groups) and the platform portal (platform baseline groups).
 *
 * Abbreviation rules enforced here, authoritative over any UI:
 *  - unique and never reused, soft-deleted groups included — it may already
 *    be printed inside historical SKUs;
 *  - a tenant group may not reuse any platform abbreviation (the tenant sees
 *    both), and a platform group may not reuse any tenant's abbreviation;
 *  - locked once the group is used by a Product (see isAbbreviationLocked()).
 * The cross-scope check cannot be expressed as one index, so every write that
 * sets an abbreviation takes a transaction-scoped advisory lock on it.
 */
class ComponentGroupService
{
    public function __construct(private readonly AuditService $audit) {}

    public function create(array $attributes, ?string $tenantId): ComponentGroup
    {
        return DB::transaction(function () use ($attributes, $tenantId) {
            $this->assertAbbreviationAvailable($attributes['abbreviation'], $tenantId, null);

            return ComponentGroup::query()->create($attributes + [
                'tenant_id' => $tenantId,
                'is_system' => $tenantId === null,
                'status' => 'ACTIVE',
            ]);
        });
    }

    public function update(ComponentGroup $group, array $attributes): ComponentGroup
    {
        return DB::transaction(function () use ($group, $attributes) {
            $group = ComponentGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (array_key_exists('abbreviation', $attributes) && $attributes['abbreviation'] !== $group->abbreviation) {
                if ($group->isAbbreviationLocked()) {
                    throw ValidationException::withMessages([
                        'abbreviation' => 'Abbreviation cannot be changed because this Component Group is already used by Products and may appear in their SKUs.',
                    ]);
                }
                $this->assertAbbreviationAvailable($attributes['abbreviation'], $group->tenant_id, $group->id);
            }

            unset($attributes['code'], $attributes['tenant_id'], $attributes['is_system']);
            $group->update($attributes);

            return $group;
        });
    }

    /**
     * Soft delete only: the group disappears from every picker for new data,
     * while Products, SKUs and transactions that reference it keep resolving
     * it (IDs, FKs and the abbreviation are untouched).
     */
    public function delete(ComponentGroup $group): void
    {
        DB::transaction(function () use ($group) {
            $group = ComponentGroup::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

            if (ComponentGroup::query()->where('parent_id', $group->id)->exists()) {
                throw ValidationException::withMessages([
                    'component_group' => 'This Component Group still has active sub-groups. Delete or move them first.',
                ]);
            }

            $group->update(['status' => 'INACTIVE']);
            $group->delete();
        });
    }

    public function restore(ComponentGroup $group): ComponentGroup
    {
        return DB::transaction(function () use ($group) {
            $group = ComponentGroup::withTrashed()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->trashed(), 422, 'This Component Group is not deleted.');

            $codeTaken = DB::table('component_groups')
                ->where('id', '!=', $group->id)
                ->whereNull('deleted_at')
                ->where('code', $group->code)
                ->where(fn ($q) => $group->tenant_id === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $group->tenant_id))
                ->exists();
            if ($codeTaken) {
                throw ValidationException::withMessages([
                    'code' => "Another active Component Group already uses code \"{$group->code}\".",
                ]);
            }

            $group->status = 'ACTIVE';
            $group->restore();
            $this->audit->log('ComponentGroup', $group->id, 'restored', null, ['code' => $group->code, 'abbreviation' => $group->abbreviation], $group->tenant_id);

            return $group;
        });
    }

    private function assertAbbreviationAvailable(string $abbreviation, ?string $tenantId, ?string $exceptId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['component_group_abbreviation:'.$abbreviation]);

        $taken = DB::table('component_groups')
            ->where('abbreviation', $abbreviation)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($tenantId !== null, fn ($q) => $q->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id')))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'abbreviation' => "The abbreviation \"{$abbreviation}\" is already used by another Component Group (deleted groups included) and cannot be reused.",
            ]);
        }
    }
}
