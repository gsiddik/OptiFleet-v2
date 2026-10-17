<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use App\Domain\Integration\AccountingFeed\AccountingFeedSnapshotBuilder;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFeedScenarios;
use Tests\TestCase;

/**
 * Audit A17: a financial fact whose projection failed is never silently lost and never silently booked. The failure is recorded and
 * reported, the repair is bounded and only ever appends, and what it appends is rebuilt from the CURRENT state of its source, so it
 * is quarantined (`review`) for the consumer instead of passing as a fact of the time.
 */
class AccountingFeedRepairTest extends TestCase
{
    use BuildsFeedScenarios;

    private const FEED = '/api/v1/integration/accounting-feed';

    private const REPAIR = ['--by' => 'ops@example.test', '--reason' => 'Projection failed during the incident'];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** The next projections of these outbox event types fail (an exception or a failing SQL statement). */
    private function failProjectionOf(array $types, bool $sqlError = false): void
    {
        $this->app->bind(AccountingFeedSnapshotBuilder::class, fn () => new class($types, $sqlError) extends AccountingFeedSnapshotBuilder
        {
            public function __construct(private array $types, private bool $sqlError) {}

            public function build(IntegrationOutboxEvent $row, string $basis = 'event_time'): ?array
            {
                if (in_array($row->event_type, $this->types, true)) {
                    $this->sqlError ? DB::select('select * from definitely_missing_table') : throw new \RuntimeException('simulated projection failure');
                }

                return parent::build($row, $basis);
            }
        });
    }

    private function projectionWorksAgain(): void
    {
        $this->app->bind(AccountingFeedSnapshotBuilder::class, fn () => new AccountingFeedSnapshotBuilder);
    }

    private function feed($tenant): array
    {
        [, $plain] = AccountingFeedCredential::issue($tenant, 'OptiEntry');

        return $this->getJson(self::FEED.'?limit=200', ['Authorization' => 'Bearer '.$plain])->assertOk()->json('data');
    }

    public function test_a_failed_projection_never_blocks_the_invoice_but_is_recorded_and_reported(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        $this->failProjectionOf(['workshop_invoice.recorded']);
        Carbon::setTestNow('2026-10-17 08:00:00');

        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $invoiceId = $this->recordInvoice($woId, $memoId, $token);

        $this->assertNotNull($invoiceId, 'the invoice was recorded');
        $this->assertSame(2, IntegrationOutboxEvent::query()->where('tenant_id', $tenant->id)->count(), 'the outbox holds both facts');
        $this->assertSame(['optifleet.maintenance_memo.billed'], array_column($this->feed($tenant), 'event_type'), 'only the fact that could be projected is in the feed');

        $issue = DB::table('accounting_feed_projection_issues')->where('tenant_id', $tenant->id)->first();
        $this->assertSame('projection_exception', $issue->reason);
        $this->assertStringContainsString('simulated projection failure', $issue->detail);
        $this->assertNull($issue->resolved_at);

        // Inside the grace period nothing is raised; afterwards the monitor fails and logs critical.
        $this->assertSame(0, Artisan::call('accounting-feed:monitor'));
        Carbon::setTestNow('2026-10-17 08:03:00');
        Log::spy();
        $this->assertSame(1, Artisan::call('accounting-feed:monitor', ['--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertSame(1, $report['missed']);
        $this->assertSame(1, $report['tenants'][$tenant->id]['missed']);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context) => $message === 'accounting_feed.missed_projections' && $context['tenant_id'] === $tenant->id)->once();
    }

    public function test_the_lost_recorded_fact_is_repaired_quarantined_while_payment_and_correction_were_already_delivered(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        $this->failProjectionOf(['workshop_invoice.recorded']);
        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $invoiceId = $this->recordInvoice($woId, $memoId, $token);
        $this->projectionWorksAgain();

        [, $checkerToken] = $this->makeTenantUser($tenant, $this->permissions());
        $correctionId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-correction", ['requested_values' => ['total_amount' => 1200000, 'subtotal' => 1100000, 'tax_total' => 100000], 'reason' => 'Typo'], $this->authHeaders($token))->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/corrections/{$correctionId}/decide", ['decision' => 'APPROVE'], $this->authHeaders($checkerToken))->assertOk();
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", ['payment_date' => '2026-09-20', 'paid_amount' => 1200000, 'evidence_url' => 'https://files.example/p.png', 'payment_method' => 'BANK_TRANSFER', 'reference_number' => 'TRX-9'], $this->authHeaders($token))->assertStatus(201);

        $published = $this->feed($tenant);
        $this->assertSame(['billed', 'corrected', 'payment_recorded', 'paid'], array_map(fn ($e) => Str::afterLast($e['event_type'], '.'), $published), 'the correction and the payment arrived, the recorded fact did not');

        // A dry run changes nothing; a repair needs to say who and why.
        $this->artisan('accounting-feed:backfill', ['--dry-run' => true])->assertExitCode(0);
        $this->artisan('accounting-feed:backfill')->assertExitCode(1);
        $this->assertSame(4, count($this->feed($tenant)));

        $this->artisan('accounting-feed:backfill', self::REPAIR)->assertExitCode(0);

        $after = $this->feed($tenant);
        $this->assertCount(5, $after);
        $this->assertSame($published, array_slice($after, 0, 4), 'nothing that was published changed, byte for byte');

        $repaired = $after[4];
        $this->assertSame('optifleet.workshop_invoice.recorded', $repaired['event_type']);
        $this->assertSame(5, $repaired['sequence'], 'appended after the others with a new sequence');
        $this->assertSame('backfill_current_state', $repaired['payload']['snapshot_basis']);
        $this->assertSame(['required' => true, 'reason' => 'backfill_current_state', 'state' => 'QUARANTINED'], array_intersect_key($repaired['review'], array_flip(['required', 'reason', 'state'])));
        $this->assertSame('ops@example.test', $repaired['review']['repaired_by']);
        $this->assertSame('Projection failed during the incident', $repaired['review']['repair_reason']);
        $this->assertSame('1200000.0000', $repaired['payload']['total_amount'], 'it shows the state NOW (after the correction), not when the invoice was recorded: that is why it is quarantined');
        foreach (array_slice($after, 0, 4) as $live) {
            $this->assertArrayNotHasKey('review', $live, 'live events carry no marker, so their envelope never changes');
        }

        $issue = DB::table('accounting_feed_projection_issues')->where('tenant_id', $tenant->id)->first();
        $this->assertSame('projected', $issue->resolution);
        $this->assertSame('ops@example.test', $issue->resolved_by);
        $audit = AuditLog::query()->where('action', 'accounting_feed.repair_run')->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(1, $audit->new_values['projected']);
        $this->assertSame('Projection failed during the incident', $audit->new_values['reason']);

        $this->assertSame(0, Artisan::call('accounting-feed:monitor', ['--grace' => 0]));
        $this->artisan('accounting-feed:backfill', self::REPAIR)->expectsOutput('Nothing is missing.')->assertExitCode(0);
        $this->assertCount(5, $this->feed($tenant));
    }

    public function test_a_repair_run_is_bounded_and_picks_up_where_it_left_off(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $this->recordInvoice($woId, $memoId, $token);
        DB::table('accounting_feed_events')->delete();
        DB::table('accounting_feed_counters')->delete();

        $this->artisan('accounting-feed:backfill', ['--limit' => 1] + self::REPAIR)->assertExitCode(0);
        $this->assertSame(1, DB::table('accounting_feed_events')->count());
        $this->artisan('accounting-feed:backfill', ['--limit' => 1] + self::REPAIR)->assertExitCode(0);
        $this->assertSame(2, DB::table('accounting_feed_events')->count());
        $this->assertSame([1, 2], DB::table('accounting_feed_events')->orderBy('sequence')->pluck('sequence')->map(fn ($s) => (int) $s)->all());
        $this->assertSame(2, DB::table('accounting_feed_events')->whereNotNull('review')->count(), 'both are quarantined');

        $this->assertSame(1000, AccountingFeedProjector::MAX_REPAIR_BATCH);
    }

    public function test_a_fact_whose_source_is_gone_alerts_until_it_is_waived_on_purpose(): void
    {
        $tenant = $this->makeTenant();
        $row = IntegrationOutboxEvent::query()->create([
            'tenant_id' => $tenant->id, 'event_type' => 'workshop_invoice.recorded', 'aggregate_type' => 'workshop_invoice', 'aggregate_id' => (string) Str::uuid(),
            'schema_version' => 1, 'correlation' => [], 'payload' => [], 'status' => 'PENDING',
        ]);

        $this->assertSame('source_missing', DB::table('accounting_feed_projection_issues')->where('outbox_event_id', $row->id)->value('reason'));
        $this->assertSame(1, Artisan::call('accounting-feed:monitor', ['--grace' => 0, '--json' => true]));
        $this->assertSame(1, json_decode(Artisan::output(), true)['unbuildable']);

        // The repair does not keep retrying it; the alert stays.
        $this->artisan('accounting-feed:backfill', self::REPAIR)->assertExitCode(0);
        $this->assertSame(0, DB::table('accounting_feed_events')->count());
        $this->assertSame(1, Artisan::call('accounting-feed:monitor', ['--grace' => 0]));
        $this->artisan('accounting-feed:backfill', ['--retry-unbuildable' => true] + self::REPAIR)->assertExitCode(0);
        $this->assertSame(2, (int) DB::table('accounting_feed_projection_issues')->where('outbox_event_id', $row->id)->value('attempts'));

        $this->artisan('accounting-feed:waive', ['outbox_event' => $row->id])->assertExitCode(1);
        $this->artisan('accounting-feed:waive', ['outbox_event' => $row->id, '--by' => 'ops@example.test', '--reason' => 'Invoice was entered by mistake and removed'])->assertExitCode(0);

        $this->assertSame(0, Artisan::call('accounting-feed:monitor', ['--grace' => 0]));
        $this->artisan('accounting-feed:backfill', ['--retry-unbuildable' => true] + self::REPAIR)->expectsOutput('Nothing is missing.')->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('action', 'accounting_feed.fact_waived')->where('tenant_id', $tenant->id)->count());
    }

    public function test_a_fact_that_already_has_an_event_cannot_be_waived(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $this->recordInvoice($woId, $memoId, $token);
        $outboxId = IntegrationOutboxEvent::query()->where('tenant_id', $tenant->id)->where('event_type', 'workshop_invoice.recorded')->value('id');

        $this->artisan('accounting-feed:waive', ['outbox_event' => $outboxId, '--by' => 'ops@example.test', '--reason' => 'No reason is good enough here'])->assertExitCode(1);
    }

    public function test_a_failing_sql_statement_inside_the_projection_cannot_poison_the_business_transaction(): void
    {
        [$tenant] = $this->setUpTenant();
        $this->failProjectionOf(['workshop_invoice.recorded'], sqlError: true);

        DB::transaction(function () use ($tenant): void {
            IntegrationOutboxEvent::query()->create([
                'tenant_id' => $tenant->id, 'event_type' => 'workshop_invoice.recorded', 'aggregate_type' => 'workshop_invoice', 'aggregate_id' => (string) Str::uuid(),
                'schema_version' => 1, 'correlation' => [], 'payload' => [], 'status' => 'PENDING',
            ]);
            // PostgreSQL aborts the whole transaction after a failed statement unless it was rolled back to a savepoint.
            DB::table('tenants')->where('id', $tenant->id)->update(['name' => 'still writable']);
        });

        $this->assertSame('still writable', DB::table('tenants')->where('id', $tenant->id)->value('name'));
        $this->assertSame(1, DB::table('accounting_feed_projection_issues')->where('tenant_id', $tenant->id)->count());
    }

    public function test_outbox_rows_that_are_not_accounting_facts_are_never_reported(): void
    {
        $tenant = $this->makeTenant();
        IntegrationOutboxEvent::query()->create([
            'tenant_id' => $tenant->id, 'event_type' => 'vehicle.odometer_updated', 'aggregate_type' => 'vehicle', 'aggregate_id' => (string) Str::uuid(),
            'schema_version' => 1, 'correlation' => [], 'payload' => [], 'status' => 'PENDING',
        ]);

        $this->assertSame(0, DB::table('accounting_feed_projection_issues')->count());
        $this->assertSame(0, Artisan::call('accounting-feed:monitor', ['--grace' => 0]));
        $this->artisan('accounting-feed:backfill', self::REPAIR)->expectsOutput('Nothing is missing.')->assertExitCode(0);
    }
}
