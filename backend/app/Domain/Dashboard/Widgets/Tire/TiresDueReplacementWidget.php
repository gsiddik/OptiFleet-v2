<?php

namespace App\Domain\Dashboard\Widgets\Tire;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Tire\Inspection\UsedTireInspectionService;
use App\Domain\Tire\Models\TireUsedInspection;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * TR-02 Tires Due Replacement (owner decision 6) — structured data only, no free-text search:
 *  (a) installed tires whose latest inspection tread depth ≤ D_pull of the applicable ACTIVE rule
 *      profile (same profile resolution as the used-tire decision engine; installed tires have no
 *      application, so only application-less profiles apply);
 *  (b) used-tire inspections with a structured recommendation still awaiting approval.
 * Installed tires that cannot be assessed (no tread reading, or no applicable profile) are counted.
 */
class TiresDueReplacementWidget extends TireWidget
{
    public function id(): string
    {
        return 'TR-02';
    }

    public function compute(DashboardContext $context): array
    {
        [$due, $noReading, $noProfile] = $this->assessInstalled($context);
        $pending = $this->pendingInspections($context);
        $byRecommendation = [];
        foreach ($pending as $row) {
            $byRecommendation[$row->recommendation] = ($byRecommendation[$row->recommendation] ?? 0) + 1;
        }

        $limitations = [];
        if ($noReading > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.tiresWithoutTreadReading', 'params' => ['count' => $noReading]];
        }
        if ($noProfile > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.tiresWithoutRuleProfile', 'params' => ['count' => $noProfile]];
        }

        return [
            'data' => [
                'installed_due' => count($due),
                'installed_items' => array_slice($due, 0, 8),
                'not_assessable' => ['no_tread_reading' => $noReading, 'no_rule_profile' => $noProfile],
                'used_pending' => count($pending),
                'used_pending_by_recommendation' => $byRecommendation,
            ],
            'limitations' => $limitations,
        ];
    }

    public function detailRules(): ?array
    {
        return ['view' => ['required', 'in:installed,used']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $rows = $params['view'] === 'installed'
            ? $this->assessInstalled($context)[0]
            : $this->pendingInspections($context)->map(fn ($r) => [
                'id' => $r->id, 'tire_id' => $r->tire_id, 'serial_number' => $r->serial_number, 'recommendation' => $r->recommendation,
                'recommendation_detail' => $r->recommendation_detail, 'inspected_at' => self::isoUtc($r->inspected_at),
                'remaining_tread_percent' => $r->remaining_tread_percent === null ? null : self::decimal($r->remaining_tread_percent),
            ])->all();
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 20)));
        $page = max(1, (int) ($params['page'] ?? 1));

        return ['items' => array_values(array_slice($rows, ($page - 1) * $perPage, $perPage)),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => count($rows), 'last_page' => max(1, (int) ceil(count($rows) / $perPage))]];
    }

    /** @return array{0: list<array>, 1: int, 2: int} due rows (lowest tread first), without reading, without profile */
    private function assessInstalled(DashboardContext $context): array
    {
        $latest = DB::table('tire_inspections as ti')->selectRaw('DISTINCT ON (ti.tire_id) ti.tire_id, ti.tread_depth_mm, ti.inspected_at')
            ->where('ti.tenant_id', $context->tenantId)->whereNotNull('ti.tread_depth_mm')
            ->orderBy('ti.tire_id')->orderByDesc('ti.inspected_at')->orderByDesc('ti.created_at');

        $tires = $this->tires($context)->whereIn('t.current_status', ['INSTALLED', 'IN_USE'])
            ->leftJoinSub($latest, 'li', 'li.tire_id', '=', 't.id')
            ->leftJoin('product_tires as pt', 'pt.product_id', '=', 't.product_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 't.current_vehicle_id')
            ->get(['t.id', 't.serial_number', 't.product_id', 't.current_position', 'pt.vehicle_group', 'li.tread_depth_mm', 'li.inspected_at',
                'v.id as vehicle_id', 'v.registration_number']);

        $service = app(UsedTireInspectionService::class);
        $profiles = [];
        $due = [];
        $noReading = 0;
        $noProfile = 0;
        foreach ($tires as $tire) {
            if ($tire->tread_depth_mm === null) {
                $noReading++;

                continue;
            }
            $category = UsedTireInspectionService::CATEGORY_BY_GROUP[$tire->vehicle_group] ?? null;
            $key = $category.'|'.$tire->product_id;
            $profile = $profiles[$key] ??= ($category === null ? null : $service->profileFor($context->tenantId, $category, $tire->product_id, null));
            if ($profile === null || $profile->d_pull_mm === null) {
                $noProfile++;

                continue;
            }
            if (BigDecimal::of((string) $tire->tread_depth_mm)->isLessThanOrEqualTo((string) $profile->d_pull_mm)) {
                $due[] = [
                    'tire_id' => $tire->id, 'serial_number' => $tire->serial_number, 'vehicle_id' => $tire->vehicle_id,
                    'registration_number' => $tire->registration_number, 'position' => $tire->current_position,
                    'tread_depth_mm' => self::decimal($tire->tread_depth_mm), 'd_pull_mm' => self::decimal($profile->d_pull_mm),
                    'inspected_at' => self::isoUtc($tire->inspected_at),
                ];
            }
        }
        usort($due, fn ($a, $b) => [(float) $a['tread_depth_mm'], $a['serial_number']] <=> [(float) $b['tread_depth_mm'], $b['serial_number']]);

        return [$due, $noReading, $noProfile];
    }

    private function pendingInspections(DashboardContext $context)
    {
        $query = DB::table('tire_used_inspections as ui')->join('tires as t', 't.id', '=', 'ui.tire_id')
            ->where('ui.tenant_id', $context->tenantId)->where('ui.status', TireUsedInspection::SUBMITTED)->whereNull('t.deleted_at');

        return $this->scopeTires($query, $context)->orderBy('ui.inspected_at')
            ->get(['ui.id', 'ui.tire_id', 't.serial_number', 'ui.recommendation', 'ui.recommendation_detail', 'ui.inspected_at', 'ui.remaining_tread_percent']);
    }
}
