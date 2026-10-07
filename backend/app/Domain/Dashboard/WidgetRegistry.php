<?php

namespace App\Domain\Dashboard;

use App\Domain\Dashboard\Widgets\Widget;

/**
 * The dashboard widget catalog (audit IDs) and the capability-based presets (tabs). A preset is
 * shown only when at least one of its widgets is available to the user; nothing here looks at role
 * names — availability comes from permissions, entitled modules and data scope.
 */
final class WidgetRegistry
{
    /** @var array<string, class-string<Widget>> */
    private const WIDGETS = [
        'FL-01' => Widgets\Fleet\FleetStatusWidget::class,
        'FL-02' => Widgets\Fleet\FleetByBranchWidget::class,
        'FL-03' => Widgets\Fleet\ActiveBreakdownsWidget::class,
        'FL-06' => Widgets\Fleet\VehicleDocumentsWidget::class,
        'MT-01' => Widgets\Maintenance\ScheduleStatusWidget::class,
        'MT-02' => Widgets\Maintenance\OverdueMaintenanceWidget::class,
        'MT-03' => Widgets\Maintenance\OpenRequestsWidget::class,
        'WS-01' => Widgets\Workshop\WorkOrderBacklogWidget::class,
        'WS-02' => Widgets\Workshop\OpenWorkOrderAgingWidget::class,
        'WS-05' => Widgets\Workshop\WorkspaceOccupancyWidget::class,
        'WS-06' => Widgets\Workshop\WaitingPartsWidget::class,
        'FN-05' => Widgets\Finance\InventoryValueWidget::class,
        'WH-01' => Widgets\Warehouse\StockHealthWidget::class,
        'WH-02' => Widgets\Warehouse\CriticalStockWidget::class,
        'WH-03' => Widgets\Warehouse\StockTransfersWidget::class,
        'PR-01' => Widgets\Procurement\ProcurementPipelineWidget::class,
        'PR-02' => Widgets\Procurement\LatePurchaseOrdersWidget::class,
        'TR-01' => Widgets\Tire\TireStatusWidget::class,
        'TR-02' => Widgets\Tire\TiresDueReplacementWidget::class,
        'TR-03' => Widgets\Tire\TiresAtVendorWidget::class,
    ];

    /**
     * Preset (tab) → ordered widget IDs: KPIs first, charts next, actions last. "summary" is the
     * executive package; for a user whose data scope is limited to branches it becomes the branch
     * package (FN-02 by vehicle instead of the FN-03 branch comparison).
     */
    public const PRESETS = [
        'summary' => ['FL-01', 'FL-03', 'FN-04', 'FN-05', 'FN-01', 'FN-03', 'FL-04', 'WS-01', 'AL-01'],
        'fleet' => ['FL-01', 'FL-03', 'MT-01', 'FL-06', 'MT-02', 'FL-02', 'FL-05', 'FL-04', 'MT-03', 'TR-02'],
        'workshop' => ['WS-01', 'WS-02', 'WS-05', 'WS-06', 'WS-03', 'WS-04', 'MT-01', 'MT-03'],
        'warehouse' => ['FN-05', 'WH-01', 'WH-02', 'WH-03', 'WH-04', 'WH-05', 'TR-01', 'TR-03'],
        'procurement' => ['PR-01', 'PR-02', 'WH-02', 'PR-03', 'FN-04', 'FN-06', 'PR-04'],
        'finance' => ['FN-01', 'FN-02', 'FN-03', 'FN-04', 'FN-05', 'FN-06', 'PR-03', 'TR-04'],
    ];

    /** @var array<string, Widget> */
    private array $instances = [];

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys(self::WIDGETS);
    }

    public function has(string $id): bool
    {
        return isset(self::WIDGETS[$id]);
    }

    public function get(string $id): Widget
    {
        return $this->instances[$id] ??= app(self::WIDGETS[$id]);
    }

    /** @return array<string, list<string>> */
    public function presetsFor(DashboardContext $context): array
    {
        $presets = self::PRESETS;
        if ($context->branchIds !== null) {
            $presets['summary'] = array_map(fn (string $id) => $id === 'FN-03' ? 'FN-02' : $id, $presets['summary']);
        }

        return $presets;
    }
}
