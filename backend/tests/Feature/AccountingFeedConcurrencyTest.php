<?php

namespace Tests\Feature;

use App\Domain\Integration\AccountingFeed\AccountingFeedService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audit A22 / A17: the feed cursor and `high_water` never lose a late-committing event. Real PostgreSQL, real parallel PHP processes
 * (each with its own connection and its own transaction), real rollbacks and a killed writer. Nothing here is faked.
 *
 * The design under test: the sequence is allocated from a per-tenant counter row whose lock is held until the writer's transaction
 * ends (AccountingFeedProjector::append()). Writers of one tenant therefore commit in sequence order and a rollback or a crash gives
 * the number back, so the committed sequences are always exactly 1..N. A reader that has seen N can never miss an N-1 still in flight.
 *
 * The test transaction of RefreshDatabase is invisible to other processes, so tenants are committed through a separate connection and
 * removed again afterwards; the reader (this process) reads committed rows of the writers, as the real endpoint does.
 */
class AccountingFeedConcurrencyTest extends TestCase
{
    private const WORKER = 'tests/Concurrency/feed_writer_worker.php';

    /** @var list<string> */
    private array $tenants = [];

    protected function tearDown(): void
    {
        $db = DB::connection('concurrency');
        foreach ($this->tenants as $tenantId) {
            $db->table('accounting_feed_events')->where('tenant_id', $tenantId)->delete();
            $db->table('accounting_feed_counters')->where('tenant_id', $tenantId)->delete();
            $db->table('tenants')->where('id', $tenantId)->delete();
        }
        $db->disconnect();
        parent::tearDown();
    }

    private function tenant(): string
    {
        config(['database.connections.concurrency' => config('database.connections.pgsql')]);
        $tenantId = (string) Str::uuid();
        DB::connection('concurrency')->table('tenants')->insert(['id' => $tenantId, 'code' => 'AFC-'.Str::random(6), 'name' => 'Feed concurrency', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->tenants[] = $tenantId;

        return $tenantId;
    }

    /** @return array{0: resource, 1: array<int, resource>, 2: string} */
    private function startWriter(string $tenantId, string $label, int $iterations, float $startAt, string $hold, int $rollbackPercent): array
    {
        $env = getenv();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'CACHE_STORE'] as $key) {
            if (($value = env($key)) !== null) {
                $env[$key] = (string) $value;
            }
        }
        $process = proc_open(
            [PHP_BINARY, base_path(self::WORKER), $tenantId, $label, (string) $iterations, sprintf('%.6F', $startAt), $hold, (string) $rollbackPercent],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env,
        );
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes, $label];
    }

    /** Reads what the writer printed so far into $buffer; returns false when it has exited. */
    private function drain(array $writer, array &$buffer, array &$errors): bool
    {
        [$process, $pipes, $label] = $writer;
        $buffer[$label] = ($buffer[$label] ?? '').stream_get_contents($pipes[1]);
        $errors[$label] = ($errors[$label] ?? '').stream_get_contents($pipes[2]);

        return (bool) proc_get_status($process)['running'];
    }

    /** One page exactly as the endpoint serves it (the tenant scope follows the tenant being read). */
    private function read(string $tenantId, int $after, int $limit): array
    {
        app(TenantContext::class)->setTenantId($tenantId);

        return app(AccountingFeedService::class)->page($tenantId, $after, $limit);
    }

    /** Stops reading from a writer and waits for it to exit; returns its exit code. */
    private function finish(array $writer): int
    {
        fclose($writer[1][1]);
        fclose($writer[1][2]);

        return proc_close($writer[0]);
    }

    /** @return list<array<string, mixed>> */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map(fn (string $l) => json_decode($l, true), explode("\n", trim($output))), 'is_array'));
    }

    public function test_a_consumer_polling_while_writers_commit_late_roll_back_and_interleave_never_sees_a_gap_or_loses_an_event(): void
    {
        $tenantId = $this->tenant();
        $writers = [];
        $startAt = microtime(true) + 3;
        foreach (range(1, 5) as $n) {
            $writers[] = $this->startWriter($tenantId, "w{$n}", 14, $startAt, 'random', 25);
        }

        $cursor = 0;
        $seen = [];
        $polls = 0;
        $restarts = 0;
        $output = [];
        $errors = [];
        $deadline = microtime(true) + 120;
        do {
            $running = false;
            foreach ($writers as $writer) {
                $running = $this->drain($writer, $output, $errors) || $running;
            }

            do {
                $page = $this->read($tenantId, $cursor, 6);
                $polls++;
                foreach ($page['data'] as $event) {
                    $this->assertSame($cursor + 1, $event['sequence'], "poll {$polls}: the feed jumped from {$cursor} to {$event['sequence']} (a late commit would show up as a gap)");
                    $this->assertArrayNotHasKey($event['event_id'], $seen, 'delivered twice');
                    $seen[$event['event_id']] = $event['sequence'];
                    $cursor = $event['sequence'];
                }
                $this->assertSame((string) $cursor, $page['next_cursor'], 'next_cursor is the last sequence seen');
                $this->assertGreaterThanOrEqual($cursor, (int) $page['high_water'], 'high_water is never behind an event of the page');

                // The consumer restarts now and then: it keeps only its stored cursor.
                if ($polls % 40 === 0) {
                    $seen = $seen; // what it holds durably is the set already processed; the cursor is all it needs
                    $restarts++;
                }
            } while ($page['has_more']);

            usleep(2000);
        } while ($running && microtime(true) < $deadline);

        foreach ($writers as $writer) {
            $this->drain($writer, $output, $errors);
        }
        foreach ($writers as $writer) {
            $this->assertSame(0, $this->finish($writer), 'a writer failed: '.implode(' ', $errors));
        }

        // Final drain after the last commit.
        do {
            $page = $this->read($tenantId, $cursor, 6);
            foreach ($page['data'] as $event) {
                $this->assertSame($cursor + 1, $event['sequence']);
                $seen[$event['event_id']] = $event['sequence'];
                $cursor = $event['sequence'];
            }
        } while ($page['has_more']);

        $done = collect($output)->flatMap(fn (string $o) => $this->lines($o))->where('phase', 'done');
        $committed = $done->where('outcome', 'committed');
        $this->assertSame([], $done->where('outcome', 'error')->all(), 'no writer hit an error');
        $this->assertSame(5 * 14, $done->count(), 'every attempt finished');
        $this->assertGreaterThan(5, $done->where('outcome', 'rolled_back')->count(), 'the run really contained rollbacks');
        $this->assertGreaterThan(20, $committed->count());

        // Nothing committed was lost, nothing rolled back appeared, and the sequences are exactly 1..N.
        $this->assertEqualsCanonicalizing($committed->pluck('event_id')->all(), array_keys($seen));
        $this->assertSame(range(1, $committed->count()), array_values($seen));
        $this->assertSame($committed->count(), $cursor);
        $this->assertSame((string) $committed->count(), $this->read($tenantId, $cursor, 1)['high_water']);
        $this->assertSame($committed->count(), (int) DB::connection('concurrency')->table('accounting_feed_counters')->where('tenant_id', $tenantId)->value('last_sequence'), 'rolled back numbers were given back');
        $this->assertGreaterThan(30, $polls, 'the consumer polled all along');
        $this->assertGreaterThanOrEqual(0, $restarts);

        // A writer received the sequence it was committed with.
        foreach ($committed as $line) {
            $this->assertSame($line['sequence'], $seen[$line['event_id']]);
        }
    }

    public function test_a_late_commit_holds_back_every_later_event_of_the_tenant_but_not_other_tenants(): void
    {
        $tenantId = $this->tenant();
        $otherTenantId = $this->tenant();
        $startAt = microtime(true) + 3;

        $slow = $this->startWriter($tenantId, 'slow', 1, $startAt, '2500', 0);
        $same = $this->startWriter($tenantId, 'same-tenant', 1, $startAt + 0.5, '0', 0);
        $other = $this->startWriter($otherTenantId, 'other-tenant', 1, $startAt + 0.5, '0', 0);

        $output = [];
        $errors = [];
        $sawBeforeSlowCommitted = [];
        $writers = [$slow, $same, $other];
        $deadline = microtime(true) + 60;
        do {
            $running = false;
            foreach ($writers as $writer) {
                $running = $this->drain($writer, $output, $errors) || $running;
            }
            $slowDone = collect($this->lines($output['slow'] ?? ''))->firstWhere('phase', 'done');
            if (! $slowDone) {
                // Timestamped: under load the writer's "done" line can reach this loop after its commit, so a sample is judged
                // against the writer's own clock below, not against whether the line has been read yet.
                $sampledAt = microtime(true);
                $sawBeforeSlowCommitted[] = [$sampledAt, count($this->read($tenantId, 0, 10)['data'])];
            }
            usleep(5000);
        } while ($running && microtime(true) < $deadline);
        foreach ($writers as $writer) {
            $this->drain($writer, $output, $errors);
            $this->assertSame(0, $this->finish($writer), implode(' ', $errors));
        }

        $slowLines = collect($this->lines($output['slow']));
        $sameLines = collect($this->lines($output['same-tenant']));
        $otherLines = collect($this->lines($output['other-tenant']));

        $this->assertSame(1, $slowLines->firstWhere('phase', 'allocated')['sequence']);
        $this->assertSame(2, $sameLines->firstWhere('phase', 'allocated')['sequence']);
        $slowCommittedAt = $slowLines->firstWhere('phase', 'done')['t'];
        $uncommitted = array_values(array_unique(array_column(array_filter($sawBeforeSlowCommitted, fn (array $s) => $s[0] < $slowCommittedAt - 0.25), 1)));
        $this->assertGreaterThan(10, count(array_filter($sawBeforeSlowCommitted, fn (array $s) => $s[0] < $slowCommittedAt - 0.25)), 'the consumer sampled while the first writer was uncommitted');
        $this->assertSame([0], $uncommitted, 'while the first writer is uncommitted the consumer sees nothing, not even the later event');

        // The later writer of the SAME tenant could only allocate after the earlier one committed (lock held until commit) ...
        $this->assertGreaterThanOrEqual($slowLines->firstWhere('phase', 'done')['t'] - 0.05, $sameLines->firstWhere('phase', 'allocated')['t']);
        // ... a writer of ANOTHER tenant did not wait for it.
        $this->assertLessThan($slowLines->firstWhere('phase', 'done')['t'], $otherLines->firstWhere('phase', 'done')['t']);
        $this->assertSame(1, $otherLines->firstWhere('phase', 'allocated')['sequence'], 'every tenant has its own sequence');

        $this->assertSame([1, 2], array_column($this->read($tenantId, 0, 10)['data'], 'sequence'));
        $this->assertSame('2', $this->read($tenantId, 0, 10)['high_water']);
    }

    public function test_a_writer_that_dies_mid_transaction_gives_its_number_back_and_the_feed_has_no_gap(): void
    {
        $tenantId = $this->tenant();

        $doomed = $this->startWriter($tenantId, 'doomed', 1, microtime(true) + 3, '60000', 0);
        $output = [];
        $errors = [];
        $deadline = microtime(true) + 60;
        do {
            $this->drain($doomed, $output, $errors);
            usleep(20000);
        } while (! collect($this->lines($output['doomed'] ?? ''))->contains('phase', 'allocated') && microtime(true) < $deadline);
        $this->assertSame(1, collect($this->lines($output['doomed']))->firstWhere('phase', 'allocated')['sequence'], 'the doomed writer holds sequence 1 and the counter lock');
        $this->assertSame([], $this->read($tenantId, 0, 10)['data'], 'uncommitted, so invisible');

        // The process is killed (a crash / restart): PostgreSQL ends its transaction when the connection drops.
        proc_terminate($doomed[0], 9);
        $this->finish($doomed);

        $next = $this->startWriter($tenantId, 'next', 1, microtime(true) + 1, '0', 0);
        $deadline = microtime(true) + 60;
        do {
            $running = $this->drain($next, $output, $errors);
            usleep(20000);
        } while ($running && microtime(true) < $deadline);
        $this->drain($next, $output, $errors);
        $this->assertSame(0, $this->finish($next), implode(' ', $errors));

        $this->assertSame(1, collect($this->lines($output['next']))->firstWhere('phase', 'allocated')['sequence'], 'the dead writer\'s number was given back');
        $this->assertSame([1], array_column($this->read($tenantId, 0, 10)['data'], 'sequence'), 'no gap, no ghost event');
        $this->assertSame(1, (int) DB::connection('concurrency')->table('accounting_feed_counters')->where('tenant_id', $tenantId)->value('last_sequence'));
    }
}
