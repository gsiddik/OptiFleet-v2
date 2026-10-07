<?php

namespace App\Domain\Dashboard;

final class DashboardPermissions
{
    /**
     * Monetary dashboard data: service cost (FN-01/02/03), inventory value (FN-05), return-to-vendor
     * refunds (FN-06), PO value (PR-03), stock movement / slow-moving values (WH-04/05) and tire
     * service cost (TR-04). Owner decision 2. Payables (FN-04) use the per-source invoice permissions.
     */
    public const FINANCE = 'dashboard.finance.view';

    /** Set the Mechanic Performance baselines (WS-07). */
    public const BASELINE_MANAGE = 'mechanic_baseline.manage';
}
