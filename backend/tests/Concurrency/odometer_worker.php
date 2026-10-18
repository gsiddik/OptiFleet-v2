<?php

/*
 * Worker process for OdometerConcurrencyTest: boots the application with its own database connection, waits for a shared start time,
 * then offers odometer values to one vehicle the way the real writers do, each in its own transaction:
 *   telematics  VehicleOdometerService::record() for every item of a JSON file, in a shuffled order (the same items as the other workers)
 *   operations  OdometerService::raiseFromOperation() for every km of a JSON list
 *   manual      OdometerService::recordManual() for every km of a JSON list
 * One JSON line per offered value on stdout: {"worker","result"} (or {"error"}).
 *
 * Usage: php odometer_worker.php <mode> <label> <tenantId> <vehicleId> <jsonFile> <startAtUnixMicrotime> <seed>
 */

use App\Domain\Integration\Optinexus\VehicleOdometerService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\OdometerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $label, $tenantId, $vehicleId, $file, $startAt, $seed] = $argv;
$values = json_decode((string) file_get_contents($file), true);
mt_srand((int) $seed);
shuffle($values);

$say = function (array $line) use ($label): void {
    fwrite(STDOUT, json_encode(['worker' => $label] + $line)."\n");
    fflush(STDOUT);
};

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$vehicle = Vehicle::query()->withoutGlobalScopes()->findOrFail($vehicleId);
foreach ($values as $value) {
    try {
        $result = match ($mode) {
            'telematics' => app(VehicleOdometerService::class)->record($tenantId, $value, now()),
            'operations' => app(OdometerService::class)->raiseFromOperation($vehicle, $value, OdometerService::SOURCE_INSPECTION, 'inspection', (string) Str::uuid())?->disposition ?? 'unchanged',
            'manual' => app(OdometerService::class)->recordManual($vehicle, $value, null, null)['outcome'],
        };
        $say(['result' => $result]);
    } catch (Throwable $e) {
        $say(['error' => get_class($e).': '.$e->getMessage()]);
    }
}
