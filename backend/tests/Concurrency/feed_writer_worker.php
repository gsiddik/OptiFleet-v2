<?php

/*
 * Worker process for AccountingFeedConcurrencyTest: boots the application with its own database connection, waits for a shared start
 * time, then performs N feed writes the way the business transaction does (BEGIN; allocate the sequence and store the event through
 * AccountingFeedProjector::append(); hold the transaction open for a while; COMMIT or ROLLBACK). One JSON line per phase on stdout.
 *
 * Usage: php feed_writer_worker.php <tenantId> <label> <iterations> <startAtUnixMicrotime> <holdMs|random> <rollbackPercent>
 */

use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $tenantId, $label, $iterations, $startAt, $hold, $rollbackPercent] = $argv;
$projector = app(AccountingFeedProjector::class);

$say = function (array $line) use ($label): void {
    fwrite(STDOUT, json_encode(['writer' => $label, 't' => microtime(true)] + $line)."\n");
    fflush(STDOUT);
};

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

for ($i = 1; $i <= (int) $iterations; $i++) {
    $eventId = (string) Str::uuid();
    $snapshot = [
        'event_type' => 'optifleet.workshop_invoice.recorded', 'occurred_at' => now()->utc(), 'correlation_id' => $eventId,
        'source' => ['type' => 'workshop_invoice', 'id' => $eventId, 'number' => "{$label}-{$i}"], 'dimensions' => [],
        'payload' => ['snapshot_basis' => 'event_time'],
    ];

    DB::beginTransaction();
    try {
        $say(['phase' => 'allocating', 'event_id' => $eventId]);
        $event = $projector->append($tenantId, $eventId, $snapshot);
        $say(['phase' => 'allocated', 'event_id' => $eventId, 'sequence' => $event->sequence]);

        usleep(1000 * ($hold === 'random' ? random_int(0, 40) : (int) $hold));

        if (random_int(1, 100) <= (int) $rollbackPercent) {
            DB::rollBack();
            $say(['phase' => 'done', 'outcome' => 'rolled_back', 'event_id' => $eventId, 'sequence' => $event->sequence]);
        } else {
            DB::commit();
            $say(['phase' => 'done', 'outcome' => 'committed', 'event_id' => $eventId, 'sequence' => $event->sequence]);
        }
    } catch (Throwable $e) {
        DB::rollBack();
        $say(['phase' => 'done', 'outcome' => 'error', 'event_id' => $eventId, 'error' => $e->getMessage()]);
    }
}
