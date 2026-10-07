<?php

namespace App\Domain\Dashboard\Widgets\Alert;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\WidgetRegistry;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\Dashboard\Widgets\Workshop\WorkOrderWidget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AL-01 Action Center — what needs attention now, one line per condition, built only from widgets the
 * user may see (each source keeps its own permission, module and scope; a source that is not allowed
 * is not computed). Every line drills into its source widget with the same rules. Conditions are
 * disjoint so one object is not alerted twice for the same reason: immobilized vs other breakdowns,
 * WOs waiting for parts vs other WOs open > 14 days.
 */
class ActionCenterWidget extends Widget
{
    public const SEVERITY_ORDER = ['critical' => 0, 'high' => 1, 'medium' => 2];

    public function __construct(private readonly WidgetRegistry $registry) {}

    public function id(): string
    {
        return 'AL-01';
    }

    public function modules(): array
    {
        return [];
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'warehouse'];
    }

    private const SOURCES = ['FL-03', 'MT-02', 'WH-02', 'WS-06', 'WS-02', 'PR-02', 'FN-04', 'FL-06', 'WH-03', 'TR-02'];

    public function isAvailable(DashboardContext $context): bool
    {
        return collect(self::SOURCES)->contains(fn ($id) => $this->source($id, $context) !== null);
    }

    private function source(string $id, DashboardContext $context): ?Widget
    {
        if (! $this->registry->has($id)) {
            return null;
        }
        $widget = $this->registry->get($id);

        return $widget->isAvailable($context) ? $widget : null;
    }

    public function compute(DashboardContext $context): array
    {
        $items = [];
        $add = function (string $type, string $severity, int $count, string $widget, array $params = [], ?string $amount = null) use (&$items) {
            if ($count > 0) {
                $items[] = ['type' => $type, 'severity' => $severity, 'count' => $count, 'amount' => $amount, 'widget' => $widget, 'params' => (object) $params];
            }
        };

        if ($w = $this->source('FL-03', $context)) {
            $d = $w->compute($context)['data'];
            $add('breakdown_immobilized', 'critical', $d['by_severity']['IMMOBILIZED'], 'FL-03', ['severity' => 'IMMOBILIZED']);
            $add('breakdown_open', 'high', $d['count'] - $d['by_severity']['IMMOBILIZED'], 'FL-03');
        }
        if ($w = $this->source('WH-02', $context)) {
            $d = $w->compute($context)['data'];
            $add('stockout_needed', 'critical', $d['needed_by_work_orders'], 'WH-02', ['needed' => 1]);
        }
        if ($w = $this->source('MT-02', $context)) {
            $add('maintenance_overdue', 'high', $w->compute($context)['data']['count'], 'MT-02');
        }
        if ($w = $this->source('WS-06', $context)) {
            $add('wo_waiting_parts', 'high', $w->compute($context)['data']['count'], 'WS-06');
        }
        if ($w = $this->source('PR-02', $context)) {
            $add('po_late', 'high', $w->compute($context)['data']['count'], 'PR-02');
        }
        if ($w = $this->source('TR-02', $context)) {
            $add('tires_due', 'high', $w->compute($context)['data']['installed_due'], 'TR-02', ['view' => 'installed']);
        }
        if ($w = $this->source('FN-04', $context)) {
            $d = $w->compute($context)['data'];
            $overdue = collect($d['buckets'])->whereIn('bucket', ['d1_30', 'd31_60', 'd61_90', 'd90_plus']);
            $add('payables_overdue', 'high', (int) $overdue->sum('count'), 'FN-04', [], $d['overdue']);
        }
        if ($w = $this->source('FL-06', $context)) {
            $d = $w->compute($context)['data'];
            $add('documents_expired', 'high', $d['expiry']['expired'], 'FL-06', ['measure' => 'expiry', 'bucket' => 'expired']);
            $add('extension_overdue', 'high', $d['extension']['expired'], 'FL-06', ['measure' => 'extension', 'bucket' => 'expired']);
            $add('documents_due_30', 'medium', $d['expiry']['d30'], 'FL-06', ['measure' => 'expiry', 'bucket' => 'd30']);
            $add('extension_due_30', 'medium', $d['extension']['d30'], 'FL-06', ['measure' => 'extension', 'bucket' => 'd30']);
        }
        if ($this->source('WS-02', $context)) {
            $add('wo_aged', 'medium', $this->agedWorkOrders($context)->count(), 'AL-01', ['type' => 'wo_aged']);
        }
        if ($w = $this->source('WH-03', $context)) {
            $d = $w->compute($context)['data'];
            $add('transfers_late', 'medium', $d['in_transit_over_threshold'], 'WH-03', ['view' => 'in_transit']);
            $add('transfer_discrepancy', 'medium', $d['received_with_discrepancy'], 'WH-03', ['view' => 'discrepancy']);
        }

        usort($items, fn ($a, $b) => [self::SEVERITY_ORDER[$a['severity']], -$a['count']] <=> [self::SEVERITY_ORDER[$b['severity']], -$b['count']]);

        return ['data' => ['items' => $items, 'total' => array_sum(array_column($items, 'count'))]];
    }

    public function detailRules(): ?array
    {
        return ['type' => ['required', 'in:wo_aged']];
    }

    /** Own list for the condition no single widget lists: open WOs older than 14 days, not waiting for parts. */
    public function detail(DashboardContext $context, array $params): array
    {
        if (! $this->source('WS-02', $context)) {
            return ['items' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]];
        }
        $query = $this->agedWorkOrders($context)
            ->leftJoin('vehicles as v', 'v.id', '=', 'wo.vehicle_id')->leftJoin('workshops as ws', 'ws.id', '=', 'wo.workshop_id')
            ->orderBy('wo.created_at')
            ->select(['wo.id', 'wo.wo_number', 'wo.status', 'wo.created_at', 'v.id as vehicle_id', 'v.registration_number', 'ws.name as workshop_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'wo_number' => $r->wo_number, 'status' => $r->status, 'vehicle_id' => $r->vehicle_id,
            'registration_number' => $r->registration_number, 'workshop_name' => $r->workshop_name, 'created_at' => self::isoUtc($r->created_at),
            'age_days' => (int) CarbonImmutable::parse($r->created_at, 'UTC')->setTimezone($context->timezone)->startOfDay()->diffInDays($context->today(), false),
        ]);
    }

    private function agedWorkOrders(DashboardContext $context)
    {
        $statuses = array_values(array_diff(WorkOrderWidget::OPEN_STATUSES, ['WAITING_PART']));
        $query = DB::table('work_orders as wo')->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at')
            ->whereIn('wo.status', $statuses)
            ->whereRaw('(?::date - '.$context->localDateSql('wo.created_at').') > 14', [$context->todayDate()]);

        return $context->scopeWorkOrder($query, 'wo.workshop_id', 'wo.branch_id');
    }
}
