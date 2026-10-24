<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Integration\AccountingFeed\AccountingFeedCredential;
use App\Domain\Integration\AccountingFeed\AccountingFeedEvent;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Vehicle\Services\VehicleAvailabilityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsFeedScenarios;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Support\FakeNexus;
use Tests\TestCase;

/**
 * Audit A19: the accounting feed is served only for the pair of products OptiNexus says the tenant has. A valid credential is not
 * enough: it must have been issued for the OptiNexus tenant the Fleet tenant is linked to now, and OptiNexus must report the tenant
 * ACTIVE with a live subscription entitling BOTH products and the consumer's connector capabilities. Built against FakeNexus (the
 * documented commercial-context JSON); no real OptiNexus.
 */
class AccountingFeedPairingTest extends TestCase
{
    use BuildsFeedScenarios, InteractsWithCentralGovernance;

    private const FEED = '/api/v1/integration/accounting-feed';

    private const ENTRY = 'optiaccounting';

    private const CONNECTOR = ['ACCOUNTING_INTEGRATION', 'OPTIFLEET_CONNECTOR'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A Fleet tenant linked to an OptiNexus tenant that holds both products and the connector (unless overridden), and its feed headers. */
    private function pairedTenant(array $apps = [FakeNexus::APP, self::ENTRY], array $capabilities = self::CONNECTOR, string $status = 'ACTIVE', ?array $subscriptions = null): array
    {
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, $capabilities, $status, $apps, $subscriptions);
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        [$credential, $plain] = AccountingFeedCredential::issue($tenant, 'OptiEntry');

        return [$tenant, ['Authorization' => 'Bearer '.$plain], $credential, $nexusTenant];
    }

    public function test_both_products_subscribed_and_the_connector_held_serves_the_feed_with_the_verified_pairing(): void
    {
        [$tenant, $headers, $credential, $nexusTenant] = $this->pairedTenant();

        $this->assertSame($nexusTenant, $credential->nexus_tenant_id, 'the credential carries the OptiNexus tenant it was issued for');
        $this->assertSame(self::ENTRY, $credential->consumer_application);

        $response = $this->getJson(self::FEED, $headers)->assertOk();
        $response->assertJsonPath('pairing.nexus_tenant_id', $nexusTenant)->assertJsonPath('pairing.producer_application', 'optifleet')->assertJsonPath('pairing.consumer_application', self::ENTRY);

        foreach (range(1, 5) as $i) {
            $this->getJson(self::FEED, $headers)->assertOk();
        }
        $this->assertSame(1, $this->nexus->callsTo('commercial-context'), 'one OptiNexus call per window, not one per pull');
    }

    public function test_every_missing_part_of_the_pairing_is_denied_with_its_own_code(): void
    {
        $cases = [
            'consumer not subscribed (absent)' => [[FakeNexus::APP], self::CONNECTOR, 'ACTIVE', null, 'FEED_CONSUMER_NOT_ENTITLED'],
            'producer not subscribed' => [[self::ENTRY], self::CONNECTOR, 'ACTIVE', null, 'FEED_PRODUCER_NOT_ENTITLED'],
            'no live subscription at all' => [[FakeNexus::APP, self::ENTRY], self::CONNECTOR, 'ACTIVE', [], 'FEED_SUBSCRIPTION_INACTIVE'],
            'only cancelled / expired subscriptions' => [[FakeNexus::APP, self::ENTRY], self::CONNECTOR, 'ACTIVE', ['CANCELLED', 'EXPIRED'], 'FEED_SUBSCRIPTION_INACTIVE'],
            'tenant suspended in OptiNexus' => [[FakeNexus::APP, self::ENTRY], self::CONNECTOR, 'SUSPENDED', null, 'FEED_TENANT_NOT_ACTIVE'],
            'connector feature missing' => [[FakeNexus::APP, self::ENTRY], ['ACCOUNTING_INTEGRATION'], 'ACTIVE', null, 'FEED_CONNECTOR_NOT_ENTITLED'],
            'no capability at all' => [[FakeNexus::APP, self::ENTRY], [], 'ACTIVE', null, 'FEED_CONNECTOR_NOT_ENTITLED'],
        ];

        foreach ($cases as $name => [$apps, $capabilities, $status, $subscriptions, $code]) {
            [, $headers] = $this->pairedTenant($apps, $capabilities, $status, $subscriptions);

            $response = $this->getJson(self::FEED, $headers);
            $this->assertSame(403, $response->status(), $name);
            $this->assertSame($code, $response->json('code'), $name);
            $this->assertNull($response->json('data'), $name.': nothing is served');
        }
    }

    public function test_a_live_but_not_yet_active_subscription_still_counts_as_the_documented_live_statuses(): void
    {
        foreach ([['TRIAL'], ['GRACE_PERIOD'], ['PAST_DUE'], ['ACTIVE', 'CANCELLED']] as $statuses) {
            [, $headers] = $this->pairedTenant(subscriptions: $statuses);
            $this->getJson(self::FEED, $headers)->assertOk();
        }
    }

    public function test_the_connector_denial_names_the_missing_capability(): void
    {
        [, $headers] = $this->pairedTenant(capabilities: ['ACCOUNTING_INTEGRATION']);

        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_CONNECTOR_NOT_ENTITLED')
            ->assertJsonPath('message', 'The tenant does not hold the connector capability: OPTIFLEET_CONNECTOR.');
    }

    public function test_another_optinexus_tenant_or_no_pairing_at_all_is_denied(): void
    {
        // The Fleet tenant is linked to another OptiNexus tenant than the credential was issued for.
        [$tenant, $headers] = $this->pairedTenant();
        $other = (string) Str::uuid();
        $this->nexus->tenant($other, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP, self::ENTRY]);
        $tenant->forceFill(['optinexus_tenant_id' => $other])->save();
        $this->forgetGovernance();
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_PAIRING_CHANGED');

        // The tenant is not linked to OptiNexus at all.
        $tenant->forceFill(['optinexus_tenant_id' => null])->save();
        $this->forgetGovernance();
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_TENANT_NOT_PAIRED');

        // A credential from before pairing existed (no OptiNexus tenant recorded) is refused until it is rotated.
        [$legacyTenant] = $this->pairedTenant();
        [, $plain] = AccountingFeedCredential::issue($legacyTenant, 'legacy');
        AccountingFeedCredential::query()->where('token_hash', AccountingFeedCredential::hash($plain))->update(['nexus_tenant_id' => null, 'consumer_application' => null]);
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$plain])->assertForbidden()->assertJsonPath('code', 'FEED_CREDENTIAL_UNPAIRED');
    }

    public function test_no_tenant_id_from_the_request_can_change_whose_feed_is_read_or_which_pairing_is_checked(): void
    {
        [, $headers, , $nexusTenant] = $this->pairedTenant();
        [$other, , , $otherNexus] = $this->pairedTenant();

        $this->getJson(self::FEED.'?tenant_id='.$other->id.'&nexus_tenant_id='.$otherNexus, $headers + ['X-Tenant-Id' => $other->id, 'X-Nexus-Tenant' => $otherNexus])
            ->assertOk()->assertJsonPath('pairing.nexus_tenant_id', $nexusTenant);

        $asked = collect($this->nexus->calls)->filter(fn (array $c) => str_contains($c['path'], 'commercial-context'))->pluck('path')->all();
        $this->assertSame(['/api/v1/tenants/'.$nexusTenant.'/commercial-context'], $asked);
    }

    public function test_an_unreachable_optinexus_answers_503_and_never_serves_a_stale_pairing(): void
    {
        [, $headers] = $this->pairedTenant();
        Carbon::setTestNow('2026-10-17 08:00:00');
        $this->getJson(self::FEED, $headers)->assertOk();

        $this->nexus->mode = 'down';
        Carbon::setTestNow('2026-10-17 08:04:59');
        $this->getJson(self::FEED, $headers)->assertOk();

        Carbon::setTestNow('2026-10-17 08:05:01');
        $this->getJson(self::FEED, $headers)->assertStatus(503)->assertJsonPath('code', 'FEED_GOVERNANCE_UNAVAILABLE')->assertHeader('Retry-After');
        $this->getJson(self::FEED, $headers)->assertStatus(503);
    }

    public function test_an_unusable_answer_from_optinexus_is_an_outage_not_an_approval(): void
    {
        [, $headers] = $this->pairedTenant();
        $this->nexus->mode = 'unbound';

        $this->getJson(self::FEED, $headers)->assertStatus(503)->assertJsonPath('code', 'FEED_GOVERNANCE_UNAVAILABLE');
    }

    public function test_a_subscription_that_ends_in_optinexus_stops_the_feed_within_the_ttl_and_a_new_one_restores_it(): void
    {
        [, $headers, , $nexusTenant] = $this->pairedTenant();
        Carbon::setTestNow('2026-10-17 08:00:00');
        $this->getJson(self::FEED, $headers)->assertOk();

        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP], ['ACTIVE']); // Entry unsubscribed
        Carbon::setTestNow('2026-10-17 08:05:01');
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_CONSUMER_NOT_ENTITLED');

        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP, self::ENTRY], ['ACTIVE']);
        Carbon::setTestNow('2026-10-17 08:10:02');
        $this->getJson(self::FEED, $headers)->assertOk();
    }

    public function test_a_revoked_credential_stops_at_once_even_while_the_pairing_answer_is_cached(): void
    {
        [, $headers, $credential] = $this->pairedTenant();
        $this->getJson(self::FEED, $headers)->assertOk();

        $credential->end('consumer disconnected');

        $this->getJson(self::FEED, $headers)->assertUnauthorized()->assertJsonPath('code', 'FEED_UNAUTHENTICATED');
    }

    public function test_fleet_keeps_recording_and_projecting_facts_while_the_feed_is_denied(): void
    {
        [$tenant, $token, $scenario] = $this->setUpTenant();
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP], ['ACTIVE']); // Entry is NOT subscribed
        $tenant->forceFill(['optinexus_tenant_id' => $nexusTenant])->save();
        [, $plain] = AccountingFeedCredential::issue($tenant, 'OptiEntry');
        $headers = ['Authorization' => 'Bearer '.$plain];

        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_CONSUMER_NOT_ENTITLED');

        [$woId, $memoId] = $this->completedMemo($scenario, $token);
        $invoiceId = $this->recordInvoice($woId, $memoId, $token);

        $this->assertNotNull($invoiceId, 'the invoice was recorded');
        // The workshop flow also announces vehicle availability changes; only the financial facts matter here.
        $this->assertSame(2, IntegrationOutboxEvent::query()->where('tenant_id', $tenant->id)->where('event_type', '!=', VehicleAvailabilityService::EVENT_TYPE)->count(), 'the outbox kept the facts');
        $this->assertSame([1, 2], AccountingFeedEvent::query()->where('tenant_id', $tenant->id)->orderBy('sequence')->pluck('sequence')->all(), 'and they are projected, waiting in the feed');
        $this->assertSame(1, $this->nexus->callsTo('commercial-context'), 'recording and projecting asked OptiNexus nothing (only the one feed pull did)');

        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP, self::ENTRY], ['ACTIVE']);
        $this->forgetGovernance();
        $this->getJson(self::FEED, $headers)->assertOk()->assertJsonPath('high_water', '2');
    }

    public function test_pairing_is_enforced_only_where_optinexus_is_in_use_unless_required(): void
    {
        $tenant = $this->makeTenant();
        [, $plain] = AccountingFeedCredential::issue($tenant, 'legacy install');
        $headers = ['Authorization' => 'Bearer '.$plain];

        config(['optinexus.enabled' => false, 'optinexus.feed.pairing' => 'auto']);
        $this->getJson(self::FEED, $headers)->assertOk()->assertJsonMissingPath('pairing');

        config(['optinexus.feed.pairing' => 'required']);
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_TENANT_NOT_PAIRED');

        config(['optinexus.enabled' => true, 'optinexus.feed.pairing' => 'auto']);
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_TENANT_NOT_PAIRED');
    }

    // ------------------------------------------------------------ lifecycle

    public function test_issue_pairs_the_credential_shows_the_token_once_and_keeps_only_its_hash(): void
    {
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP, self::ENTRY]);
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);

        $exit = Artisan::call('accounting-feed:issue-credential', ['tenant' => $tenant->code, '--name' => 'OptiEntry prod', '--by' => 'ops@example.test', '--expires-in-days' => 30]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Pairing: ready', $output);
        $this->assertSame(1, preg_match_all('/ofaf_[A-Za-z0-9]{48}/', $output, $tokens), 'the token is printed once');
        $plain = $tokens[0][0];

        $credential = AccountingFeedCredential::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($nexusTenant, $credential->nexus_tenant_id);
        $this->assertSame('ops@example.test', $credential->issued_by);
        $this->assertSame(AccountingFeedCredential::hash($plain), $credential->token_hash);
        $this->assertTrue($credential->expires_at->isFuture());
        $this->getJson(self::FEED, ['Authorization' => 'Bearer '.$plain])->assertOk();

        $audit = AuditLog::query()->where('action', 'accounting_feed.credential_issued')->firstOrFail();
        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($nexusTenant, $audit->new_values['nexus_tenant_id']);
        $this->assertSame('ops@example.test', $audit->new_values['by']);

        // Neither the credential row nor the audit trail holds anything that could be replayed as a token.
        $stored = json_encode([DB::table('accounting_feed_credentials')->get(), DB::table('audit_logs')->get()]);
        $this->assertStringNotContainsString($plain, $stored);
        $this->assertSame(0, preg_match('/ofaf_[A-Za-z0-9]{40,}/', $stored), 'no full token is stored anywhere');
    }

    public function test_issue_refuses_a_tenant_that_is_not_linked_while_pairing_is_enforced_and_warns_when_not_ready(): void
    {
        $unlinked = $this->makeTenant();
        $this->artisan('accounting-feed:issue-credential', ['tenant' => $unlinked->code])->assertExitCode(1);
        $this->assertSame(0, AccountingFeedCredential::query()->where('tenant_id', $unlinked->id)->count());

        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, self::CONNECTOR, 'ACTIVE', [FakeNexus::APP]);
        $notReady = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        $this->artisan('accounting-feed:issue-credential', ['tenant' => $notReady->code])
            ->expectsOutputToContain('FEED_CONSUMER_NOT_ENTITLED')->assertExitCode(0);
    }

    public function test_rotation_issues_a_paired_replacement_and_ends_the_old_credential_after_the_grace_period(): void
    {
        [$tenant, $oldHeaders, $old] = $this->pairedTenant();
        Carbon::setTestNow('2026-10-17 09:00:00');

        $this->artisan('accounting-feed:rotate-credential', ['credential' => $old->id, '--grace-minutes' => 30, '--by' => 'ops@example.test'])->assertExitCode(0);

        $new = AccountingFeedCredential::query()->where('rotated_from_id', $old->id)->firstOrFail();
        $this->assertSame($old->nexus_tenant_id, $new->nexus_tenant_id);
        $this->assertSame($old->name, $new->name);
        $this->assertNotSame($old->token_hash, $new->token_hash);
        $old->refresh();
        $this->assertSame('rotated', $old->revoked_reason);
        $this->assertTrue($old->expires_at->equalTo(Carbon::parse('2026-10-17 09:30:00')));

        // The consumer can still swap secrets during the grace period; afterwards only the new credential works.
        $this->getJson(self::FEED, $oldHeaders)->assertOk();
        Carbon::setTestNow('2026-10-17 09:30:01');
        $this->getJson(self::FEED, $oldHeaders)->assertUnauthorized();
        $this->assertSame(1, AuditLog::query()->where('action', 'accounting_feed.credential_rotated')->where('tenant_id', $tenant->id)->count());
    }

    public function test_rotation_without_grace_ends_the_old_credential_at_once_and_repairs_an_unpaired_one(): void
    {
        [$tenant, $headers, $old] = $this->pairedTenant();
        $old->forceFill(['nexus_tenant_id' => null, 'consumer_application' => null])->save();
        $this->getJson(self::FEED, $headers)->assertForbidden()->assertJsonPath('code', 'FEED_CREDENTIAL_UNPAIRED');

        $this->artisan('accounting-feed:rotate-credential', ['credential' => $old->id, '--grace-minutes' => 0])->assertExitCode(0);

        $this->getJson(self::FEED, $headers)->assertUnauthorized();
        $new = AccountingFeedCredential::query()->where('rotated_from_id', $old->id)->firstOrFail();
        $this->assertSame($tenant->optinexus_tenant_id, $new->nexus_tenant_id, 'the replacement is paired with the CURRENT OptiNexus tenant');
        $this->artisan('accounting-feed:rotate-credential', ['credential' => 'missing'])->assertExitCode(1);
    }

    public function test_revoke_records_who_and_why_and_the_list_shows_state_and_pairing_without_tokens(): void
    {
        [$tenant, $headers, $credential] = $this->pairedTenant();

        $this->artisan('accounting-feed:revoke-credential', ['credential' => $credential->id, '--reason' => 'Entry disconnected', '--by' => 'ops@example.test'])->assertExitCode(0);

        $credential->refresh();
        $this->assertSame('Entry disconnected', $credential->revoked_reason);
        $this->getJson(self::FEED, $headers)->assertUnauthorized();
        $audit = AuditLog::query()->where('action', 'accounting_feed.credential_revoked')->firstOrFail();
        $this->assertSame('ops@example.test', $audit->new_values['by']);

        $this->artisan('accounting-feed:credentials', ['tenant' => $tenant->code])->expectsOutputToContain('revoked')->assertExitCode(0);
        $this->artisan('accounting-feed:credentials', ['tenant' => 'NOPE'])->assertExitCode(1);
    }
}
