<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * True-parallel first print: several separate PHP processes, each with its own database connection,
 * request the print of the same never-printed document at the same instant. The advisory lock must leave
 * exactly one generation, and every process must receive that same generation.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so the tenant is committed
 * through a separate connection and removed again afterwards.
 */
class DocumentGenerationConcurrencyTest extends TestCase
{
    private const WORKERS = 8;

    private const ROUNDS = 3;

    public function test_concurrent_first_prints_create_exactly_one_generation(): void
    {
        config(['database.connections.concurrency' => config('database.connections.pgsql')]);
        $db = DB::connection('concurrency');
        $tenantId = (string) Str::uuid();
        $db->table('tenants')->insert(['id' => $tenantId, 'code' => 'DGC-'.Str::random(6), 'name' => 'Concurrency', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        try {
            for ($round = 1; $round <= self::ROUNDS; $round++) {
                $sourceId = (string) Str::uuid();
                $ids = $this->runWorkers($tenantId, $sourceId);

                $this->assertCount(self::WORKERS, $ids, "Round {$round}: every worker answered.");
                $this->assertCount(1, array_unique($ids), "Round {$round}: every worker received the same generation.");
                $this->assertSame(1, $db->table('document_generations')->where('source_entity_id', $sourceId)->count(), "Round {$round}: exactly one first generation.");
            }
        } finally {
            $db->table('document_generations')->where('tenant_id', $tenantId)->delete();
            $db->table('tenants')->where('id', $tenantId)->delete();
            $db->disconnect();
        }
    }

    /** @return list<string> the generation id each worker received */
    private function runWorkers(string $tenantId, string $sourceId): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        // Workers boot in well under a second; the shared start time releases them together.
        $startAt = sprintf('%.6F', microtime(true) + 2);
        $processes = [];
        for ($i = 0; $i < self::WORKERS; $i++) {
            $process = proc_open(
                [PHP_BINARY, base_path('tests/Concurrency/first_print_worker.php'), $tenantId, $sourceId, $startAt],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
            );
            $processes[] = [$process, $pipes];
        }

        $ids = [];
        foreach ($processes as [$process, $pipes]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            $err = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), "Worker failed: {$err}");
            $ids[] = $out;
        }

        return $ids;
    }
}
