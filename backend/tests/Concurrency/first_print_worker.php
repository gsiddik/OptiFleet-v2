<?php

/*
 * Worker process for DocumentGenerationConcurrencyTest: boots the application with its own database
 * connection, waits for a shared start time, then asks for the print of one document that has no
 * generation yet. Prints the id of the generation it received.
 *
 * Usage: php first_print_worker.php <tenantId> <sourceId> <startAtUnixMicrotime>
 */

use App\Domain\DocumentGeneration\Services\DocumentGenerationService;
use App\Domain\DocumentGeneration\Support\DocumentSource;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $tenantId, $sourceId, $startAt] = $argv;
$source = new DocumentSource('rfq', 'rfq', $sourceId, $tenantId, 'concurrency.pdf', fn (string $locale) => [], fallbackHtml: '<p>concurrency</p>');

while (microtime(true) < (float) $startAt) {
    usleep(100);
}

echo app(DocumentGenerationService::class)->forPrint($source, null, null, null)->id;
