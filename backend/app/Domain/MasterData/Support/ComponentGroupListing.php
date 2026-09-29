<?php

namespace App\Domain\MasterData\Support;

use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Query + presentation for Component Group lists, shared by the tenant and
 * platform controllers. Adds `is_used` (Product usage) and
 * `abbreviation_locked` to every row; all pre-existing properties are kept.
 */
final class ComponentGroupListing
{
    private const SORTABLE = ['sequence', 'code', 'name', 'abbreviation', 'status', 'updated_at'];

    public static function apply(Builder $query, Request $request): Builder
    {
        $query->withUsage();

        match ($request->string('trashed')->value()) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('abbreviation', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->string('parent_id')->value() ?: null);
        }
        if ($request->has('is_system')) {
            $query->where('is_system', $request->boolean('is_system'));
        }

        $sort = $request->string('sort')->value();
        if (in_array($sort, self::SORTABLE, true)) {
            $query->orderBy($sort, $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('sequence')->orderBy('name');
    }

    public static function present(ComponentGroup $group): ComponentGroup
    {
        $used = $group->getAttribute('is_used');
        if ($used === null) {
            $used = $group->isUsedByProducts();
        }

        $group->setAttribute('is_used', (bool) $used);
        $group->setAttribute('abbreviation_locked', $group->abbreviation !== null && (bool) $used);
        $group->setAttribute('is_deleted', $group->trashed());

        return $group;
    }
}
