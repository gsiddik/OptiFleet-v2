<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Integration\AccountingFeed\AccountingFeedEvent;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Partner\Models\Partner;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * OA6 accounting feed: GET /api/v1/integration/accounting-feed (docs/integration/ACCOUNTING_FEED.md).
 */
class AccountingFeedTest extends TestCase
{
    private const FEED = '/api/v1/integration/accounting-feed';

    /** @return array{0: Tenant, 1: string, 2: array<string, mixed>} tenant, user token, scenario */
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'AF-'.Str::random(4)]);
        foreach (['VEHICLE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());

        return [$tenant, $token, compact('tenant', 'branch', 'workshop', 'vehicle')];
    }

    private function permissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.submit', 'work_order.approve',
            'work_order.assign', 'work_order.schedule', 'work_order.start', 'work_order.cancel',
            'work_order_external_service.create', 'work_order_external_service.complete', 'work_order_external_service.cancel',
            'workshop_invoice.record', 'workshop_invoice.view', 'workshop_invoice.upload_payment',
            'workshop_invoice.request_correction', 'workshop_invoice.request_cancellation',
            'workshop_invoice.verify_correction', 'workshop_invoice.verify_cancellation',
            'workshop_invoice.view_settlement_history',
        ];
    }

    /** @return array{0: string, 1: string} [workOrderId, memoId] with the memo COMPLETED */
    private function completedMemo(array $scenario, string $token, ?Partner $partner = null): array
    {
        $headers = $this->authHeaders($token);
        $partner ??= $this->makePartner($scenario['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP', 'tax_id' => '01.234.567.8-901.000']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $scenario['vehicle']->id, 'workshop_id' => $scenario['workshop']->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
        foreach (['submit', 'approve', 'assign', 'schedule'] as $step) {
            $this->postJson("/api/v1/app/work-orders/{$woId}/{$step}", [], $headers)->assertOk();
        }
        $this->withApprovedWorkspace($woId);
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();
        $memoId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Engine overhaul', 'cost' => 1000000,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$memoId}/complete", [], $headers)->assertOk();

        return [$woId, $memoId];
    }

    private function recordInvoice(string $woId, string $memoId, string $token, array $overrides = []): string
    {
        return $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$memoId}/workshop-invoice", array_merge([
            'external_invoice_number' => 'INV-'.Str::upper(Str::random(6)),
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-15', 'currency' => 'IDR',
            'subtotal' => 900000, 'tax_total' => 99000, 'discount_total' => 0, 'total_amount' => 999000,
            'partner_reference' => 'PR-1',
            'line_items' => [['description' => 'Overhaul labour', 'quantity' => 1, 'unit_price' => 900000, 'line_total' => 900000]],
        ], $overrides), $this->authHeaders($token))->assertStatus(201)->json('data.id');
    }

    /** Credential for the tenant; returns the Authorization headers. */
    private function feedHeaders(Tenant $tenant): array
    {
        [, $plain] = AccountingFeedCredential::issue($tenant, 'OptiEntry test');

        return ['Authorization' => 'Bearer '.$plain];
    }

    /** One tenant with a recorded invoice (feed sequences 1 and 2). */
    private function tenantWithInvoice(): array
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $invoiceId = $this->recordInvoice($woId, $memoId, $token, ['external_invoice_number' => 'INV-FEED-1']);

        return [$tenant, $token, $scenario, $woId, $memoId, $invoiceId];
    }

    public function test_recorded_invoice_and_memo_billed_appear_with_the_documented_contract(): void
    {
        [$tenant, , $scenario, $woId, $memoId, $invoiceId] = $this->tenantWithInvoice();

        $response = $this->getJson(self::FEED, $this->feedHeaders($tenant))->assertOk();
        $data = $response->json('data');

        $this->assertSame(['optifleet.workshop_invoice.recorded', 'optifleet.maintenance_memo.billed'], array_column($data, 'event_type'));
        $this->assertSame([1, 2], array_column($data, 'sequence'));
        $this->assertSame('2', $response->json('next_cursor'));
        $this->assertFalse($response->json('has_more'));

        $recorded = $data[0];
        $this->assertSame(IntegrationOutboxEvent::query()->where('event_type', 'workshop_invoice.recorded')->value('id'), $recorded['event_id']);
        $this->assertSame('1.0', $recorded['schema_version']);
        $this->assertSame('optifleet', $recorded['source_system']);
        $this->assertSame($tenant->id, $recorded['source_tenant_reference']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $recorded['occurred_at']);
        $this->assertSame($woId, $recorded['correlation_id']);
        $this->assertSame(['type' => 'workshop_invoice', 'id' => $invoiceId, 'number' => 'INV-FEED-1'], $recorded['source']);
        $this->assertSame($scenario['vehicle']->id, $recorded['dimensions']['vehicle']);
        $this->assertSame($woId, $recorded['dimensions']['work_order']);
        $this->assertSame($scenario['branch']->id, $recorded['dimensions']['branch']);

        $payload = $recorded['payload'];
        $this->assertSame('INV-FEED-1', $payload['invoice_number']);
        $this->assertSame('2026-09-01', $payload['invoice_date']);
        $this->assertSame('2026-09-15', $payload['due_date']);
        $this->assertSame('IDR', $payload['currency']);
        $this->assertSame(['900000.0000', '0.0000', '99000.0000', '999000.0000'], [$payload['subtotal'], $payload['discount_total'], $payload['tax_total'], $payload['total_amount']]);
        $this->assertSame('01.234.567.8-901.000', $payload['vendor']['tax_id']);
        $this->assertSame('PR-1', $payload['partner_reference']);
        $this->assertSame([['description' => 'Overhaul labour', 'quantity' => '1.0000', 'unit_price' => '900000.0000', 'line_total' => '900000.0000']], $payload['lines']);
        $this->assertSame($memoId, $payload['maintenance_memo']['id']);
        $this->assertSame('event_time', $payload['snapshot_basis']);

        $billed = $data[1];
        $this->assertSame('maintenance_memo', $billed['source']['type']);
        $this->assertTrue($billed['payload']['informational']);
        $this->assertSame($invoiceId, $billed['payload']['workshop_invoice']['id']);
    }

    public function test_payment_correction_and_cancellation_events_and_event_time_snapshots(): void
    {
        [$tenant, $token, $scenario, $woId, $memoId, $invoiceId] = $this->tenantWithInvoice();
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->permissions());
        $maker = $this->authHeaders($token);
        $checker = $this->authHeaders($checkerToken);

        $correctionId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-correction", [
            'requested_values' => ['total_amount' => 1200000, 'subtotal' => 1100000, 'tax_total' => 100000, 'due_date' => '2026-09-30'], 'reason' => 'Typo',
        ], $maker)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/corrections/{$correctionId}/decide", ['decision' => 'APPROVE'], $checker)->assertOk();
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1200000, 'evidence_url' => 'https://files.example/p.png',
            'payment_method' => 'BANK_TRANSFER', 'reference_number' => 'TRX-9',
        ], $maker)->assertStatus(201);
        $cancellationId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-cancellation", ['reason' => 'Wrong WO'], $maker)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/cancellations/{$cancellationId}/decide", ['decision' => 'APPROVE'], $checker)->assertOk();

        $data = $this->getJson(self::FEED, $this->feedHeaders($tenant))->assertOk()->json('data');
        $byType = collect($data)->keyBy('event_type');

        $this->assertSame(
            ['recorded', 'billed', 'corrected', 'payment_recorded', 'paid', 'cancelled'],
            array_map(fn ($e) => Str::afterLast($e['event_type'], '.'), $data),
        );
        $this->assertSame(range(1, 6), array_column($data, 'sequence'));

        // The first event keeps what was true when it happened; the correction does not rewrite it.
        $this->assertSame('999000.0000', $byType['optifleet.workshop_invoice.recorded']['payload']['total_amount']);

        $corrected = $byType['optifleet.workshop_invoice.corrected']['payload'];
        $this->assertSame('1200000.0000', $corrected['total_amount']);
        $this->assertSame('2026-09-30', $corrected['due_date']);
        $this->assertSame('Typo', $corrected['correction']['reason']);
        $this->assertEquals(['total_amount' => '999000.0000', 'subtotal' => '900000.0000', 'tax_total' => '99000.0000', 'due_date' => '2026-09-15'], $corrected['correction']['previous_values']);
        $this->assertEquals(['total_amount' => '1200000.0000', 'subtotal' => '1100000.0000', 'tax_total' => '100000.0000', 'due_date' => '2026-09-30'], $corrected['correction']['requested_values']);

        $payment = $byType['optifleet.workshop_invoice.payment_recorded']['payload'];
        $this->assertSame(['amount' => '1200000.0000', 'payment_date' => '2026-09-20', 'method' => 'BANK_TRANSFER', 'reference_number' => 'TRX-9'], array_diff_key($payment['payment'], ['id' => 1]));
        $this->assertSame('1200000.0000', $payment['total_amount']);
        $this->assertTrue($byType['optifleet.maintenance_memo.paid']['payload']['informational']);

        $cancelled = $byType['optifleet.workshop_invoice.cancelled']['payload'];
        $this->assertSame(['reason' => 'Wrong WO', 'had_payment' => true], array_diff_key($cancelled['cancellation'], ['id' => 1]));
        $this->assertSame('INV-FEED-1', $cancelled['invoice_number']);
    }

    public function test_cursor_is_exclusive_pages_by_sequence_and_an_empty_page_keeps_the_cursor(): void
    {
        [$tenant] = $this->tenantWithInvoice();
        $headers = $this->feedHeaders($tenant);

        $first = $this->getJson(self::FEED.'?limit=1', $headers)->assertOk();
        $this->assertSame([1], array_column($first->json('data'), 'sequence'));
        $this->assertSame('1', $first->json('next_cursor'));
        $this->assertTrue($first->json('has_more'));

        $second = $this->getJson(self::FEED.'?limit=1&after='.$first->json('next_cursor'), $headers)->assertOk();
        $this->assertSame([2], array_column($second->json('data'), 'sequence'));
        $this->assertSame('2', $second->json('next_cursor'));
        $this->assertFalse($second->json('has_more'));

        $empty = $this->getJson(self::FEED.'?after=2', $headers)->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame('2', $empty->json('next_cursor'));
        $this->assertFalse($empty->json('has_more'));

        $this->assertSame([1, 2], array_column($this->getJson(self::FEED.'?after=0', $headers)->json('data'), 'sequence'));
        $this->assertSame([], $this->getJson(self::FEED.'?after=999999999', $headers)->json('data'));
    }

    public function test_order_follows_the_sequence_not_the_timestamp(): void
    {
        [$tenant] = $this->tenantWithInvoice();
        // An older occurred_at on a later sequence must not move it ahead.
        DB::table('accounting_feed_events')->where('tenant_id', $tenant->id)->where('sequence', 2)->delete();
        DB::table('accounting_feed_events')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sequence' => 2, 'event_id' => (string) Str::uuid(),
            'event_type' => 'optifleet.maintenance_memo.paid', 'occurred_at' => '2000-01-01 00:00:00+00', 'correlation_id' => 'x',
            'source' => '{}', 'dimensions' => '{}', 'payload' => '{}',
        ]);

        $data = $this->getJson(self::FEED, $this->feedHeaders($tenant))->json('data');
        $this->assertSame([1, 2], array_column($data, 'sequence'));
        $this->assertSame('2000-01-01T00:00:00.000Z', $data[1]['occurred_at']);
    }

    public function test_limit_and_cursor_are_validated(): void
    {
        [$tenant] = $this->setUpTenant();
        $headers = $this->feedHeaders($tenant);

        foreach (['limit=0', 'limit=201', 'limit=abc', 'limit=1.5', 'after=-1', 'after=abc', 'after=1e3', 'after=99999999999999999999'] as $query) {
            $this->getJson(self::FEED.'?'.$query, $headers)->assertStatus(422);
        }
        $this->getJson(self::FEED.'?limit=200&after=0', $headers)->assertOk();
        $this->getJson(self::FEED.'?limit=1', $headers)->assertOk();
    }

    public function test_default_limit_is_100(): void
    {
        [$tenant] = $this->setUpTenant();
        $now = now();
        DB::table('accounting_feed_counters')->insert(['tenant_id' => $tenant->id, 'last_sequence' => 105]);
        DB::table('accounting_feed_events')->insert(array_map(fn ($n) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sequence' => $n, 'event_id' => (string) Str::uuid(),
            'event_type' => 'optifleet.maintenance_memo.paid', 'occurred_at' => $now, 'correlation_id' => 'c',
            'source' => '{}', 'dimensions' => '{}', 'payload' => '{}',
        ], range(1, 105)));

        $response = $this->getJson(self::FEED, $this->feedHeaders($tenant))->assertOk();
        $this->assertCount(100, $response->json('data'));
        $this->assertSame('100', $response->json('next_cursor'));
        $this->assertTrue($response->json('has_more'));
    }

    public function test_tenant_comes_only_from_the_credential(): void
    {
        [$tenantA] = $this->tenantWithInvoice();
        [$tenantB, $tokenB, $scenarioB] = $this->setUpTenant();
        [$woB, $memoB] = $this->completedMemo($scenarioB, $tokenB);
        $this->recordInvoice($woB, $memoB, $tokenB, ['external_invoice_number' => 'INV-B-ONLY']);

        $forA = $this->feedHeaders($tenantA);
        $forB = $this->feedHeaders($tenantB);

        $dataA = $this->getJson(self::FEED, $forA)->json('data');
        $dataB = $this->getJson(self::FEED, $forB)->json('data');
        $this->assertSame([1, 2], array_column($dataA, 'sequence'));
        $this->assertSame([1, 2], array_column($dataB, 'sequence'), 'sequences are per tenant');
        $this->assertSame([$tenantA->id], array_unique(array_column($dataA, 'source_tenant_reference')));
        $this->assertSame([$tenantB->id], array_unique(array_column($dataB, 'source_tenant_reference')));
        $this->assertSame('INV-B-ONLY', $dataB[0]['payload']['invoice_number']);
        $this->assertSame([], array_intersect(array_column($dataA, 'event_id'), array_column($dataB, 'event_id')));

        // A tenant id in the query, a header or as another tenant's code changes nothing.
        $attempt = $this->getJson(self::FEED.'?tenant_id='.$tenantB->id.'&tenant='.$tenantB->code, $forA + ['X-Tenant-Id' => $tenantB->id]);
        $attempt->assertOk();
        $this->assertSame($dataA, $attempt->json('data'));
    }

    public function test_authentication_failures(): void
    {
        [$tenant, $userToken] = $this->setUpTenant();
        $this->getJson(self::FEED)->assertStatus(401)->assertHeader('WWW-Authenticate', 'Bearer');
        $this->getJson(self::FEED, ['Authorization' => 'Bearer nope'])->assertStatus(401);
        $this->getJson(self::FEED, ['Authorization' => 'Bearer ofaf_'.Str::random(48)])->assertStatus(401);
        $this->getJson(self::FEED, ['Authorization' => 'Basic '.base64_encode('a:b')])->assertStatus(401);
        // A tenant user's own API token is not a machine credential.
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$userToken])->assertStatus(401);
        // The hash, not the token, is what is stored: presenting the hash must not work.
        [$credential] = AccountingFeedCredential::issue($tenant, 'x');
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$credential->token_hash])->assertStatus(401);

        [$revoked, $revokedToken] = AccountingFeedCredential::issue($tenant, 'revoked');
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$revokedToken])->assertStatus(401);

        [, $expiredToken] = AccountingFeedCredential::issue($tenant, 'expired', now()->subMinute());
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$expiredToken])->assertStatus(401);

        [$scopeless, $scopelessToken] = AccountingFeedCredential::issue($tenant, 'no scope');
        $scopeless->forceFill(['scopes' => ['something.else']])->save();
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$scopelessToken])->assertStatus(403)->assertJsonPath('code', 'FEED_SCOPE_MISSING');

        $this->getJson(self::FEED, $this->feedHeaders($tenant))->assertOk();
        $tenant->forceFill(['status' => 'SUSPENDED'])->save();
        $this->getJson(self::FEED, $this->feedHeaders($tenant))->assertStatus(403)->assertJsonPath('code', 'FEED_TENANT_NOT_ACTIVE');
    }

    public function test_only_get_is_allowed_and_serving_a_page_writes_nothing(): void
    {
        [$tenant] = $this->tenantWithInvoice();
        $headers = $this->feedHeaders($tenant);
        $snapshot = fn () => [
            DB::table('accounting_feed_events')->orderBy('sequence')->get()->all(),
            DB::table('accounting_feed_counters')->get()->all(),
            DB::table('accounting_feed_credentials')->get()->all(),
            DB::table('integration_outbox_events')->count(),
        ];
        $before = $snapshot();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $this->getJson(self::FEED.'?after=0&limit=5', $headers)->assertOk();
        $this->getJson(self::FEED.'?after=5', $headers)->assertOk();
        $this->getJson(self::FEED, ['Authorization' => 'Bearer bad'])->assertStatus(401);

        $this->assertSame([], $writes);
        $this->assertEquals($before, $snapshot());

        $this->postJson(self::FEED, [], $headers)->assertStatus(405);
        $this->putJson(self::FEED, [], $headers)->assertStatus(405);
        $this->deleteJson(self::FEED, [], $headers)->assertStatus(405);
    }

    public function test_a_rolled_back_business_transaction_leaves_no_event_and_no_gap(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        [$woA, $memoA] = $this->completedMemo($scenario, $token);
        [$woB, $memoB] = $this->completedMemo($scenario, $token);
        $this->recordInvoice($woA, $memoA, $token);

        DB::beginTransaction();
        $this->recordInvoice($woB, $memoB, $token);
        $this->assertSame(4, AccountingFeedEvent::query()->where('tenant_id', $tenant->id)->count());
        DB::rollBack();

        $this->assertSame([1, 2], AccountingFeedEvent::query()->where('tenant_id', $tenant->id)->orderBy('sequence')->pluck('sequence')->all());
        $this->assertSame(2, (int) DB::table('accounting_feed_counters')->where('tenant_id', $tenant->id)->value('last_sequence'));

        $this->recordInvoice($woB, $memoB, $token);
        $this->assertSame(range(1, 4), AccountingFeedEvent::query()->where('tenant_id', $tenant->id)->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_feed_events_are_append_only_and_tenant_bound_at_the_database(): void
    {
        [$tenant] = $this->tenantWithInvoice();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append-only');
        DB::table('accounting_feed_events')->where('tenant_id', $tenant->id)->update(['sequence' => 99]);
    }

    public function test_sequence_is_unique_per_tenant_and_positive(): void
    {
        [$tenant] = $this->tenantWithInvoice();
        $row = (array) DB::table('accounting_feed_events')->where('tenant_id', $tenant->id)->where('sequence', 1)->first();
        $row['id'] = (string) Str::uuid();
        $row['event_id'] = (string) Str::uuid();

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('accounting_feed_events')->insert($row);
    }

    public function test_backfill_adds_missing_events_once_and_marks_the_snapshot_basis(): void
    {
        [$tenant, , , , , $invoiceId] = $this->tenantWithInvoice();
        // Rows that predate the feed: no feed events, no counter.
        DB::table('accounting_feed_events')->delete();
        DB::table('accounting_feed_counters')->delete();
        $headers = $this->feedHeaders($tenant);
        $this->assertSame([], $this->getJson(self::FEED, $headers)->json('data'));

        $this->artisan('accounting-feed:backfill', ['--tenant' => $tenant->id])->expectsOutput('Projected 2, skipped 0.')->assertExitCode(0);
        $this->artisan('accounting-feed:backfill')->expectsOutput('Projected 0, skipped 0.')->assertExitCode(0);

        $data = $this->getJson(self::FEED, $headers)->json('data');
        $this->assertSame([1, 2], array_column($data, 'sequence'));
        $this->assertSame($invoiceId, $data[0]['source']['id']);
        $this->assertSame('backfill_current_state', $data[0]['payload']['snapshot_basis']);
    }

    public function test_credential_commands_issue_a_working_token_and_revoke_it(): void
    {
        [$tenant] = $this->tenantWithInvoice();

        $this->artisan('accounting-feed:issue-credential', ['tenant' => $tenant->code, '--name' => 'OptiEntry prod'])->assertExitCode(0);
        $this->artisan('accounting-feed:issue-credential', ['tenant' => 'NOPE'])->assertExitCode(1);

        $credential = AccountingFeedCredential::query()->where('tenant_id', $tenant->id)->where('name', 'OptiEntry prod')->firstOrFail();
        $this->assertSame(['accounting.feed.read'], $credential->scopes);
        $this->assertSame(64, strlen($credential->token_hash));
        $this->assertStringStartsWith('ofaf_', $credential->token_prefix);

        [$other, $plain] = AccountingFeedCredential::issue($tenant, 'to revoke');
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$plain])->assertOk();
        $this->artisan('accounting-feed:revoke-credential', ['credential' => $other->id])->assertExitCode(0);
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$plain])->assertStatus(401);
        $this->artisan('accounting-feed:revoke-credential', ['credential' => 'missing'])->assertExitCode(1);
    }

    public function test_a_credential_cannot_be_bound_to_a_tenant_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);
        AccountingFeedCredential::query()->create([
            'tenant_id' => (string) Str::uuid(), 'name' => 'x', 'token_prefix' => 'ofaf_', 'token_hash' => hash('sha256', 'x'), 'scopes' => ['accounting.feed.read'],
        ]);
    }
}
