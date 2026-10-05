<?php

namespace App\Domain\Tire\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Tire History / recent activity: one feed over the existing tire event records — installations,
 * rotations, inspections, removals, retread and repair cycles — plus scrapped tires (scrapping is
 * a status change with no event row, so its time is the tire's last update). Read-only; tenant and
 * the tire index's data scope apply.
 */
class TireActivityService
{
    public const TYPES = ['INSTALLATION', 'ROTATION', 'INSPECTION', 'REMOVAL', 'RETREAD', 'REPAIR', 'SCRAP'];

    public function __construct(private readonly TireInventoryService $inventory) {}

    /** @param  list<string>  $types */
    public function feed(string $tenantId, User $user, array $types, ?string $search, int $perPage, bool $completedCyclesOnly = false): LengthAwarePaginator
    {
        $sources = array_values(array_filter(array_map(fn (string $type) => $this->source($type, $tenantId, $completedCyclesOnly), $types ?: self::TYPES)));
        $union = array_shift($sources);
        foreach ($sources as $source) {
            $union->unionAll($source);
        }

        $query = DB::query()->fromSub($union, 'e')
            ->join('tires', 'tires.id', '=', 'e.tire_id')
            ->leftJoin('products as p', 'p.id', '=', 'tires.product_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'e.vehicle_id')
            ->where('tires.tenant_id', $tenantId)->whereNull('tires.deleted_at')
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('tires.serial_number', 'ilike', "%{$search}%")
                ->orWhere('v.registration_number', 'ilike', "%{$search}%")->orWhere('p.name', 'ilike', "%{$search}%")))
            ->select(['e.type', 'e.id', 'e.tire_id', 'e.occurred_at', 'e.vehicle_id', 'e.position', 'e.odometer', 'e.status', 'e.detail', 'e.stage', 'e.cycle_opened_at',
                'tires.serial_number', 'tires.current_status', 'tires.product_id', 'p.name as product_name', 'p.brand as product_brand', 'v.registration_number'])
            ->orderByDesc('e.occurred_at')->orderBy('e.type')->orderBy('e.id');

        $page = $this->inventory->scopeToUser($query, $tenantId, $user)->paginate($perPage);

        // Retread / repair cycles: the tire's usage up to the moment it entered the cycle (its
        // installation periods ended by then), computed for the page's rows only.
        $cutoffs = $page->getCollection()->filter(fn ($r) => $r->cycle_opened_at !== null)
            ->mapWithKeys(fn ($r) => ["{$r->type}-{$r->id}" => [$r->tire_id, (string) $r->cycle_opened_at]])->all();
        $usage = $cutoffs ? $this->inventory->usageBefore($cutoffs) : [];
        $page->setCollection($page->getCollection()->map(function ($r) use ($usage) {
            $cycle = $usage["{$r->type}-{$r->id}"] ?? null;
            $r->usage_km = $cycle['km'] ?? null;
            $r->usage_hours = $cycle['hours'] ?? null;
            unset($r->cycle_opened_at);

            return $r;
        }));

        return $page;
    }

    /**
     * The last vehicle / wheel position the tire was on before a cycle opened: its latest
     * installation that had ended (removed) by then.
     */
    private function previousInstallation(string $alias, string $column): string
    {
        return "(select pi.{$column} from tire_installations pi where pi.tire_id = {$alias}.tire_id and pi.removed_at is not null and pi.removed_at <= {$alias}.created_at order by pi.removed_at desc, pi.created_at desc limit 1)";
    }

    private function source(string $type, string $tenantId, bool $completedCyclesOnly = false): ?Builder
    {
        $row = fn (Builder $q, string $id, string $tire, string $at, string $vehicle, string $position, string $odometer, string $status, string $detail, string $stage = 'null::text', string $openedAt = 'null::timestamp') => $q
            ->where(explode('.', $id)[0].'.tenant_id', $tenantId)
            ->selectRaw("'{$type}' as type, {$id} as id, {$tire} as tire_id, {$at} as occurred_at, {$vehicle} as vehicle_id, {$position} as position, {$odometer} as odometer, {$status} as status, {$detail} as detail, {$stage} as stage, {$openedAt} as cycle_opened_at");

        return match ($type) {
            'INSTALLATION' => $row(DB::table('tire_installations as i'), 'i.id', 'i.tire_id', 'i.installed_at', 'i.vehicle_id', 'i.wheel_position::text', 'i.installation_odometer', "case when i.removed_at is null then 'ACTIVE' else 'ENDED' end", 'i.installation_source::text'),
            'ROTATION' => $row(DB::table('tire_rotations as r'), 'r.id', 'r.tire_id', 'r.occurred_at', 'r.vehicle_id', "(r.from_position || ' → ' || r.to_position)", 'r.odometer', 'null::text', 'null::text'),
            'INSPECTION' => $row(DB::table('tire_inspections as n'), 'n.id', 'n.tire_id', 'n.inspected_at', 'null::uuid', 'null::text', 'null::numeric', 'n.condition::text',
                "concat_ws(' · ', case when n.tread_depth_mm is not null then n.tread_depth_mm::text || ' mm' end, n.recommendation::text)"),
            'REMOVAL' => $row(DB::table('tire_removals as m')->leftJoin('tire_installations as mi', 'mi.id', '=', 'm.tire_installation_id'), 'm.id', 'm.tire_id', 'm.removed_at', 'mi.vehicle_id', 'mi.wheel_position::text', 'm.removal_odometer', 'm.disposition::text', 'm.removal_reason::text'),
            'RETREAD' => $row(DB::table('tire_retreads as t')->when($completedCyclesOnly, fn ($q) => $q->whereIn('t.status', ['APPROVED', 'REJECTED'])), 't.id', 't.tire_id', 'coalesce(t.approved_at, t.final_inspected_at, t.received_at, t.sent_at, t.created_at)', $this->previousInstallation('t', 'vehicle_id'), $this->previousInstallation('t', 'wheel_position').'::text', 'null::numeric', "coalesce(t.final_status, t.status::text)", "'Cycle ' || t.cycle_number", 't.status::text', 't.created_at'),
            'REPAIR' => $row(DB::table('tire_repairs as a')->when($completedCyclesOnly, fn ($q) => $q->whereIn('a.status', ['APPROVED', 'REJECTED'])), 'a.id', 'a.tire_id', 'coalesce(a.approved_at, a.final_inspected_at, a.received_at, a.sent_at, a.created_at)', $this->previousInstallation('a', 'vehicle_id'), $this->previousInstallation('a', 'wheel_position').'::text', 'null::numeric', "coalesce(a.final_status, a.status::text)", "'Cycle ' || a.cycle_number", 'a.status::text', 'a.created_at'),
            'SCRAP' => $row(DB::table('tires as s')->where('s.current_status', 'SCRAPPED'), 's.id', 's.id', 's.updated_at', 'null::uuid', 'null::text', 'null::numeric', 's.current_status::text', 'null::text'),
            default => null,
        };
    }
}
