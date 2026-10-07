<?php

namespace App\Domain\Dashboard;

/** Validated global dashboard filters. Ids are checked against tenant + data scope before use. */
final class DashboardFilters
{
    /** Allowed numbers of full months before the current month. */
    public const MONTH_OPTIONS = [3, 6, 12];

    public const DEFAULT_MONTHS = 12;

    public function __construct(
        public readonly ?string $branchId = null,
        public readonly ?string $workshopId = null,
        public readonly ?string $warehouseId = null,
        public readonly int $months = self::DEFAULT_MONTHS,
    ) {}

    public function toArray(): array
    {
        return [
            'branch_id' => $this->branchId,
            'workshop_id' => $this->workshopId,
            'warehouse_id' => $this->warehouseId,
            'months' => $this->months,
        ];
    }
}
