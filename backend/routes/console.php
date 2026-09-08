<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Commercial lifecycle automation (Phase 2, Section 54). Every job here is
// idempotent — safe to overlap or re-run without side effects beyond the
// intended state transition.
Schedule::command('billing:generate')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('invoices:evaluate-overdue')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('subscriptions:evaluate-grace-period')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('contracts:evaluate-expiry')->dailyAt('02:30')->withoutOverlapping();

// Phase 5 Section 32: escalate unresolved notifications on a short cycle —
// escalation waits are typically measured in minutes/hours, not days.
Schedule::command('notifications:process-escalations')->everyFiveMinutes()->withoutOverlapping();

// Phase 6 Section 7: daily analytics ETL. Time is configurable (not
// hardcoded to one server timezone) via ANALYTICS_SCHEDULE_TIME — default
// 02:00 UTC, late enough that same-day operational data has settled for
// the great majority of tenant timezones. Idempotent (Section 9): safe to
// re-fire without creating duplicate analytical records.
Schedule::command('analytics:run')->dailyAt(config('analytics.schedule_time', '02:00'))->withoutOverlapping();

// Phase 7 Section 46-47: feature generation runs after the analytics ETL
// settles (Section 1 — features are built from the Phase 6 analytical
// layer, not raw OLTP scans), then prediction/recommendation generation
// runs after that. Each stage's own schedule time is configurable so the
// dependency order survives changes to either without editing this file.
Schedule::command('intelligence:generate-features')->dailyAt(config('intelligence.schedule.feature_time', '03:00'))->withoutOverlapping();
Schedule::command('intelligence:predict')->dailyAt(config('intelligence.schedule.predict_time', '03:30'))->withoutOverlapping();
