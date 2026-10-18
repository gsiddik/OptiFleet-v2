<?php

namespace Tests\Feature\Governance;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Governance\Exceptions\GovernanceContractException;
use App\Domain\Governance\NexusGovernanceClient;
use App\Domain\Governance\NexusTokenProvider;
use App\Domain\Identity\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\Support\FakeNexus;
use Tests\TestCase;

/**
 * Audit A29 / R06: the product switcher is what OptiNexus answers to POST /authorization/product-context, never a static
 * list. Built against FakeNexus and JSON fixtures written from the documented contract (GOVERNANCE_CONTRACTS.md section 3);
 * a real OptiNexus is not involved.
 */
class ProductSwitcherTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
        config(['optinexus.sso.frontend_url' => 'http://fleet.test']);
        $this->nexus->products = [
            ['code' => 'optiradar', 'name' => 'OptiRadar', 'launch_url' => 'http://radar.test/app?lang=id', 'current' => false],
            ['code' => 'optientry', 'name' => 'OptiEntry', 'launch_url' => 'http://entry.test/login', 'current' => false],
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Tenant, 1: User, 2: string} */
    private function signedIn(): array
    {
        $tenant = $this->makeCentralTenant(['optifleet.vehicle']);
        [$user, $token] = $this->makeCentralUser($tenant, ['vehicle.view']);

        return [$tenant, $user, $token];
    }

    public function test_the_list_is_what_optinexus_answers_without_the_current_product_the_portal_or_any_link(): void
    {
        [, , $token] = $this->signedIn();
        $this->nexus->products[] = ['code' => 'cgo', 'name' => 'Governance portal', 'launch_url' => 'http://nexus.test/portal', 'current' => false];

        $data = $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->json('data');

        $this->assertTrue($data['available']);
        $this->assertSame([['code' => 'optiradar', 'name' => 'OptiRadar'], ['code' => 'optientry', 'name' => 'OptiEntry']], $data['products']);
        $this->assertStringNotContainsString('http', json_encode($data), 'the browser is given no link before it asks for one product');
        $this->assertSame(1, $this->nexus->callsTo('product-context'));
        $call = collect($this->nexus->calls)->first(fn (array $c) => str_contains($c['path'], 'product-context'));
        $this->assertSame(['tenant_id', 'user_id'], collect(array_keys($call['body']))->sort()->values()->all(), 'the application is the service account\'s, never sent by us');
    }

    public function test_links_that_are_not_safe_targets_are_left_out(): void
    {
        [, , $token] = $this->signedIn();
        $this->nexus->products = [
            ['code' => 'ok', 'name' => 'Fine', 'launch_url' => 'http://ok.test/app', 'current' => false],
            ['code' => 'script', 'name' => 'Script', 'launch_url' => 'javascript:alert(1)', 'current' => false],
            ['code' => 'creds', 'name' => 'Creds', 'launch_url' => 'http://user:pw@creds.test/', 'current' => false],
            ['code' => 'nolink', 'name' => 'No link', 'launch_url' => null, 'current' => false],
            ['code' => 'broken', 'name' => 'Broken', 'launch_url' => 'not a url', 'current' => false],
            ['code' => 'here', 'name' => 'Same origin', 'launch_url' => 'http://fleet.test/other', 'current' => false],
            ['code' => 'portal', 'name' => 'Nexus origin', 'launch_url' => FakeNexus::BASE.'/anything', 'current' => false],
            ['code' => 'OPTIFLEET', 'name' => 'Myself by code', 'launch_url' => 'http://elsewhere.test/', 'current' => false],
            ['code' => 'ok', 'name' => 'Duplicate', 'launch_url' => 'http://dup.test/', 'current' => false],
        ];

        $codes = collect($this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->json('data.products'))->pluck('code')->all();

        $this->assertSame(['ok'], $codes);
    }

    public function test_outside_local_and_testing_only_https_links_are_offered(): void
    {
        [, , $token] = $this->signedIn();
        $this->nexus->products = [
            ['code' => 'plain', 'name' => 'Plain', 'launch_url' => 'http://plain.test/', 'current' => false],
            ['code' => 'secure', 'name' => 'Secure', 'launch_url' => 'https://secure.test/', 'current' => false],
        ];
        $this->app['env'] = 'production';

        $codes = collect($this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->json('data.products'))->pluck('code')->all();

        $this->assertSame(['secure'], $codes);
        $this->app['env'] = 'testing';
    }

    public function test_launch_returns_the_launch_url_with_the_nexus_tenant_as_tenant_hint(): void
    {
        [$tenant, $user, $token] = $this->signedIn();

        $response = $this->postJson('/api/v1/auth/products/launch', ['code' => 'optiradar'], $this->authHeaders($token))->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $url = $response->json('data.url');

        $parts = parse_url($url);
        $this->assertSame('radar.test', $parts['host']);
        parse_str($parts['query'], $query);
        $this->assertSame($tenant->optinexus_tenant_id, $query['tenant_hint'], 'the tenant id is OptiNexus\' own, never a local one');
        $this->assertSame('id', $query['lang'], 'the query of the launch URL is kept');
        $this->assertCount(2, $query, 'nothing else travels in the URL: no token, no code, no secret');

        $audit = AuditLog::query()->where('action', 'product.launched')->latest('created_at')->firstOrFail();
        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame('optiradar', $audit->new_values['code']);
    }

    public function test_launch_asks_optinexus_again_even_when_the_list_is_cached(): void
    {
        [, , $token] = $this->signedIn();

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        $this->assertSame(1, $this->nexus->callsTo('product-context'), 'a render inside the cache window costs no call');

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optientry'], $this->authHeaders($token))->assertOk();
        $this->assertSame(2, $this->nexus->callsTo('product-context'), 'a navigation to another product is always checked');

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optientry'], $this->authHeaders($token))->assertOk();
        $this->assertSame(3, $this->nexus->callsTo('product-context'));
    }

    public function test_a_product_removed_at_optinexus_is_refused_even_if_the_browser_still_lists_it(): void
    {
        [, , $token] = $this->signedIn();
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();

        $this->nexus->products = [['code' => 'optientry', 'name' => 'OptiEntry', 'launch_url' => 'http://entry.test/login', 'current' => false]];

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optiradar'], $this->authHeaders($token))
            ->assertForbidden()->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE')->assertJsonMissingPath('data.url');
        $this->assertSame(1, AuditLog::query()->where('action', 'product.launch_refused')->count());
    }

    public function test_a_direct_target_is_refused_the_portal_the_current_product_and_unknown_codes(): void
    {
        [, , $token] = $this->signedIn();
        $this->nexus->products[] = ['code' => 'cgo', 'name' => 'Governance portal', 'launch_url' => 'http://nexus.test/portal', 'current' => false];

        foreach (['cgo', 'CGO', FakeNexus::APP, 'unknown', 'optiradar-evil'] as $code) {
            $this->postJson('/api/v1/auth/products/launch', ['code' => $code], $this->authHeaders($token))
                ->assertForbidden()->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE');
        }
        foreach (['', 'a b', 'http://evil.test', str_repeat('a', 65), ['x']] as $code) {
            $this->postJson('/api/v1/auth/products/launch', ['code' => $code], $this->authHeaders($token))->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/products/launch', ['url' => 'http://evil.test'], $this->authHeaders($token))->assertUnprocessable();
    }

    public function test_the_refusal_is_translated_and_keeps_its_english_code(): void
    {
        [, $user, $token] = $this->signedIn();
        $user->forceFill(['preferred_locale' => 'id'])->save();

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'nope'], $this->authHeaders($token))
            ->assertForbidden()->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE')->assertJsonPath('message', 'Produk itu tidak tersedia untuk Anda dari sini. Daftar telah dimuat ulang.');
    }

    public function test_the_list_is_kept_for_the_ttl_and_not_one_second_longer(): void
    {
        [, , $token] = $this->signedIn();
        Carbon::setTestNow('2026-10-17 08:00:00');
        $this->nexus->ttl = 60;

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        Carbon::setTestNow('2026-10-17 08:00:59');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        $this->assertSame(1, $this->nexus->callsTo('product-context'));

        Carbon::setTestNow('2026-10-17 08:01:01');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        $this->assertSame(2, $this->nexus->callsTo('product-context'));
    }

    public function test_the_cache_never_exceeds_300_seconds_whatever_optinexus_says(): void
    {
        [, , $token] = $this->signedIn();
        Carbon::setTestNow('2026-10-17 08:00:00');
        $this->nexus->ttl = 86400;

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        Carbon::setTestNow('2026-10-17 08:05:01');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();

        $this->assertSame(2, $this->nexus->callsTo('product-context'));
    }

    public function test_the_cache_is_per_person(): void
    {
        [$tenant, , $token] = $this->signedIn();
        [, $otherToken] = $this->makeCentralUser($tenant, ['vehicle.view']);

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk();
        $this->getJson('/api/v1/auth/products', $this->authHeaders($otherToken))->assertOk();

        $this->assertSame(2, $this->nexus->callsTo('product-context'));
    }

    /** OptiNexus withdraws the application for this person while the (up to 300 s old) access answer is still cached. */
    private function withdrawBehindTheCachedAccess(string $reason): array
    {
        [$tenant, $user, $token] = $this->signedIn();
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertOk(); // warms the access answer
        $this->nexus->users[$tenant->optinexus_tenant_id.'|'.$user->optinexus_subject]['session_reason'] = $reason;

        return [$tenant, $user, $token];
    }

    public function test_when_optinexus_withdraws_the_application_a_launch_ends_the_session(): void
    {
        [, , $token] = $this->withdrawBehindTheCachedAccess('application_access_revoked');

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optiradar'], $this->authHeaders($token))
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED_CENTRALLY')->assertJsonPath('reason', 'application_access_revoked')->assertJsonMissingPath('data');

        $this->assertNull(PersonalAccessToken::findToken($token), 'the token cannot be replayed');
        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertUnauthorized();
    }

    public function test_a_withdrawal_seen_while_listing_also_ends_the_session_and_lists_nothing(): void
    {
        [, , $token] = $this->withdrawBehindTheCachedAccess('tenant_membership_removed');

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))
            ->assertUnauthorized()->assertJsonPath('code', 'SESSION_ENDED_CENTRALLY')->assertJsonPath('reason', 'tenant_membership_removed')->assertJsonMissingPath('data');
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_an_unreachable_optinexus_lists_nothing_and_opens_nothing(): void
    {
        [, , $token] = $this->signedIn();
        $this->getJson('/api/v1/app/vehicles', $this->authHeaders($token))->assertOk();
        $this->nexus->mode = 'down';

        // Not cached yet: nothing is shown, and the page itself is not broken by the switcher.
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_unavailable')->assertJsonPath('data.products', []);

        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optiradar'], $this->authHeaders($token))
            ->assertStatus(503)->assertJsonPath('code', 'GOVERNANCE_UNAVAILABLE')->assertJsonMissingPath('data.url');
    }

    /** A tenant that is linked to OptiNexus but not governed by it (legacy): product-context is the only call that is made. */
    private function linkedLocalSession(): string
    {
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, []);
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        [$user] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->nexus->grant($nexusTenant, $user->optinexus_subject, ['vehicle.view']);

        return $this->ssoToken($user, $tenant);
    }

    public function test_no_stale_list_is_served_once_the_answer_expired_and_optinexus_is_gone(): void
    {
        $token = $this->linkedLocalSession();
        Carbon::setTestNow('2026-10-17 08:00:00');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->assertJsonPath('data.available', true);

        $this->nexus->mode = 'down';
        Carbon::setTestNow('2026-10-17 08:00:30');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->assertJsonPath('data.available', true);

        Carbon::setTestNow('2026-10-17 08:05:01');
        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_unavailable')->assertJsonPath('data.products', []);
    }

    public function test_an_unusable_answer_is_treated_like_an_outage(): void
    {
        $token = $this->linkedLocalSession();
        $this->nexus->mode = 'unbound';

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'provider_unavailable')->assertJsonPath('data.products', []);
    }

    public function test_only_an_optinexus_sign_in_has_anything_to_switch_to(): void
    {
        $nexusTenant = (string) Str::uuid();
        $this->nexus->tenant($nexusTenant, []);
        $tenant = $this->makeTenant(['optinexus_tenant_id' => $nexusTenant]);
        [$user] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $this->nexus->grant($nexusTenant, $user->optinexus_subject, ['vehicle.view']);
        $password = $user->createToken('test', ['tenant:'.$tenant->id])->plainTextToken; // a password session: no `sso` ability

        $this->getJson('/api/v1/auth/products', $this->authHeaders($password))
            ->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.reason', 'not_sso_session');
        $this->postJson('/api/v1/auth/products/launch', ['code' => 'optiradar'], $this->authHeaders($password))->assertForbidden()->assertJsonPath('code', 'PRODUCT_NOT_AVAILABLE');
        $this->assertSame(0, $this->nexus->callsTo('product-context'));
    }

    public function test_a_tenant_or_person_not_linked_to_optinexus_has_no_switcher(): void
    {
        $legacy = $this->makeTenant();
        [$user] = $this->makeTenantUser($legacy, ['vehicle.view']);
        $user->forceFill(['optinexus_subject' => (string) Str::uuid()])->save();
        $token = $this->ssoToken($user, $legacy);

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->assertJsonPath('data.reason', 'not_linked');

        $linked = $this->makeTenant(['optinexus_tenant_id' => (string) Str::uuid()]);
        [$noSubject] = $this->makeTenantUser($linked, ['vehicle.view']);
        $this->getJson('/api/v1/auth/products', $this->authHeaders($this->ssoToken($noSubject, $linked)))->assertOk()->assertJsonPath('data.reason', 'not_linked');

        $this->assertSame(0, $this->nexus->callsTo('product-context'));
    }

    public function test_a_platform_session_has_no_switcher(): void
    {
        [$platform] = $this->makePlatformUser();
        $token = $platform->createToken('test', ['platform'])->plainTextToken;

        $this->getJson('/api/v1/auth/products', $this->authHeaders($token))->assertOk()->assertJsonPath('data.available', false);
        $this->assertSame(0, $this->nexus->callsTo('product-context'));
    }

    public function test_the_tenant_comes_from_the_session_never_from_the_request(): void
    {
        [$tenant, $user, $token] = $this->signedIn();
        $otherNexusTenant = (string) Str::uuid();

        $this->getJson('/api/v1/auth/products?tenant_id='.$otherNexusTenant.'&user_id='.Str::uuid(), $this->authHeaders($token) + ['X-Tenant-ID' => $otherNexusTenant])->assertOk();

        $call = collect($this->nexus->calls)->first(fn (array $c) => str_contains($c['path'], 'product-context'));
        $this->assertSame($tenant->optinexus_tenant_id, $call['body']['tenant_id']);
        $this->assertSame($user->optinexus_subject, $call['body']['user_id']);
        $this->assertSame(NexusTokenProvider::SCOPES_GOVERNANCE, collect($this->nexus->calls)->firstWhere('path', '/api/v1/oauth/token')['scope']);
    }

    public function test_the_sso_start_forwards_a_tenant_hint_only_when_it_is_a_uuid(): void
    {
        config(['optinexus.sso.client_id' => 'fleet-sso', 'optinexus.sso.client_secret' => 'not-a-real-secret']);
        $hint = (string) Str::uuid();

        $location = $this->get('/api/v1/auth/sso/redirect?tenant_hint='.strtoupper($hint))->assertRedirect()->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame($hint, $query['tenant_hint']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('S256', $query['code_challenge_method']);

        foreach (['not-a-uuid', '<script>alert(1)</script>', "$hint\nX-Injected: 1", $hint.'&redirect_uri=http://evil.test'] as $bad) {
            $location = $this->get('/api/v1/auth/sso/redirect?tenant_hint='.urlencode($bad))->assertRedirect()->headers->get('Location');
            parse_str(parse_url($location, PHP_URL_QUERY), $query);
            $this->assertArrayNotHasKey('tenant_hint', $query, $bad);
            $this->assertStringNotContainsString('evil.test', $location);
        }
    }

    public function test_the_memberships_carry_the_optinexus_tenant_for_the_login_page(): void
    {
        [$tenant, , $token] = $this->signedIn();

        $this->getJson('/api/v1/auth/me', $this->authHeaders($token))->assertOk()
            ->assertJsonPath('data.memberships.0.optinexus_tenant_id', $tenant->optinexus_tenant_id);
    }

    // ------------------------------------------------------------- contract

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/fixtures/nexus/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Only these stubs answer (FakeNexus is not in the way). */
    private function onlyNexusAnswers(array $stubs): void
    {
        Http::swap(new Factory);
        Http::fake($stubs);
        app(NexusTokenProvider::class)->forget(NexusTokenProvider::SCOPES_GOVERNANCE);
    }

    public function test_contract_the_documented_answer_is_read_as_documented(): void
    {
        $this->onlyNexusAnswers([
            FakeNexus::BASE.'/api/v1/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'x']),
            FakeNexus::BASE.'/api/v1/authorization/product-context' => Http::response($this->fixture('product-context-allowed')),
        ]);

        $result = app(NexusGovernanceClient::class)->productContext('8f1c1d3a-0b0e-4f55-9c1d-0d0f6f6f0001', '3d2b6f0e-6a58-4c7e-b6b1-5f1d2c9a0001');

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['deny_reason']);
        $this->assertSame('ACME', $result['tenant']['code']);
        $this->assertSame(['optifleet', 'optiradar', 'optientry'], array_column($result['products'], 'code'));
        $this->assertTrue($result['products'][0]['current']);
        $this->assertSame('https://radar.example.test/app?lang=id', $result['products'][1]['launch_url']);
        $this->assertSame(300, $result['ttl_seconds']);

        Http::assertSent(function (Request $request) {
            if (! str_ends_with($request->url(), '/authorization/product-context')) {
                return false;
            }

            return $request->method() === 'POST' && $request->data() === ['user_id' => '8f1c1d3a-0b0e-4f55-9c1d-0d0f6f6f0001', 'tenant_id' => '3d2b6f0e-6a58-4c7e-b6b1-5f1d2c9a0001'];
        });
    }

    public function test_contract_a_denied_answer_carries_the_logout_vocabulary_reason(): void
    {
        $this->onlyNexusAnswers([
            FakeNexus::BASE.'/api/v1/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'x']),
            FakeNexus::BASE.'/api/v1/authorization/product-context' => Http::response($this->fixture('product-context-denied')),
        ]);

        $result = app(NexusGovernanceClient::class)->productContext('u', 't');

        $this->assertFalse($result['allowed']);
        $this->assertSame('application_access_revoked', $result['deny_reason']);
        $this->assertNull($result['tenant']);
        $this->assertSame([], $result['products']);
    }

    public function test_contract_an_unsupported_schema_version_is_refused(): void
    {
        $this->onlyNexusAnswers([
            FakeNexus::BASE.'/api/v1/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'x']),
            FakeNexus::BASE.'/api/v1/authorization/product-context' => Http::response($this->fixture('product-context-future-schema')),
        ]);

        $this->expectException(GovernanceContractException::class);
        $this->expectExceptionMessage('unsupported schema_version [2.0]');

        app(NexusGovernanceClient::class)->productContext('u', 't');
    }

    public function test_contract_an_unbound_service_account_is_a_contract_error_with_its_code(): void
    {
        $this->onlyNexusAnswers([
            FakeNexus::BASE.'/api/v1/oauth/token' => Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'x']),
            FakeNexus::BASE.'/api/v1/authorization/product-context' => Http::response($this->fixture('service-account-not-bound'), 403),
        ]);

        try {
            app(NexusGovernanceClient::class)->productContext('u', 't');
            $this->fail('expected a contract error');
        } catch (GovernanceContractException $e) {
            $this->assertSame('SERVICE_ACCOUNT_NOT_BOUND', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
    }
}
