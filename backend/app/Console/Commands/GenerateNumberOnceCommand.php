<?php

namespace App\Console\Commands;

use App\Domain\Configuration\Services\DocumentNumberingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Test-only helper invoked as a genuinely independent OS process (never
 * forked) by Phase5ConcurrencySmokeTestCommand, so the numbering
 * concurrency probe never shares an inherited DB/Redis socket between
 * "concurrent" workers — each is a real, separate `php artisan` process,
 * exactly like real concurrent web requests.
 */
class GenerateNumberOnceCommand extends Command
{
    protected $signature = 'numbering:generate-once {documentType} {tenantId} {branchId?}';
    protected $description = 'Generate a single configurable document number and print it (test harness only)';

    public function handle(DocumentNumberingService $numbering): int
    {
        $result = DB::transaction(fn () => $numbering->generate(
            $this->argument('documentType'),
            $this->argument('tenantId'),
            $this->argument('branchId'),
        ));

        $this->line($result['document_number']);

        return self::SUCCESS;
    }
}
