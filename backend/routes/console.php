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
