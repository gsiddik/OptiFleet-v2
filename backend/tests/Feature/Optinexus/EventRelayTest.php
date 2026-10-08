<?php

namespace Tests\Feature\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use App\Domain\Integration\Services\IntegrationOutboxService;
use App\Domain\WorkOrder\Models\WorkshopInvoice;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventRelayTest extends TestCase
{
    private const NEXUS = 'http://nexus.test';

    protected Tenant $linked;

    private Tenant $unlinked;

    private string $nexusTenantId;

    /** Answers of POST /events in order: [status, body] or a Throwable to throw. Defaults to 201. */
    protected array $answers = [];

    /** Every POST /events that reached the fake, including those answered with a connection error. */
    private int $eventHits = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'optinexus.enabled' => true,
            'optinexus.base_url' => self::NEXUS,
            'optinexus.events.enabled' => true,
            'optinexus.events.max_attempts' => 5,
            'optinexus.gateway.client_id' => 'svc',
            'optinexus.gateway.client_secret' => 'secret',
        ]);
        Cache::flush();

        $this->nexusTenantId = (string) Str::uuid();
        $this->linked = $this->makeTenant(['optinexus_tenant_id' => $this->nexusTenantId]);
        $this->unlinked = $this->makeTenant();

        Http::fake([
            self::NEXUS.'/api/v1/oauth/token' => Http::response(['access_token' => 'tok-1']),
            self::NEXUS.'/api/v1/events' => function () {
                $this->eventHits++;
                $answer = array_shift($this->answers) ?? [201, ['data' => ['id' => 'x']]];
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return Http::response($answer[1], $answer[0]);
            },
        ]);
    }

    protected function record(Tenant $tenant, string $type = 'workshop_invoice.recorded', ?string $aggregateId = null): IntegrationOutboxEvent
    {
        return app(IntegrationOutboxService::class)->record(
            $tenant->id, $type, WorkshopInvoice::class, $aggregateId ?? (string) Str::uuid(),
            ['work_order_id' => (string) Str::uuid(), 'partner_id' => (string) Str::uuid()],
            ['external_invoice_number' => 'INV-77', 'total_amount' => '1500000.00', 'currency' => 'IDR'],
        );
    }

    /** @return array<int, Request> */
    private function eventCalls(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/v1/events'))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    protected function relay(): array
    {
        return app(OptinexusEventRelay::class)->relay();
    }

    public function test_a_pending_event_of_a_linked_tenant_is_delivered_once_with_its_own_id(): void
    {
        $row = $this->record($this->linked);

        $this->assertSame(['delivered' => 1, 'retrying' => 0, 'failed' => 0], $this->relay());

        $row->refresh();
        $this->assertSame('DELIVERED', $row->status);
        $this->assertNotNull($row->delivered_at);
        $this->assertSame(1, $row->attempts);

        $calls = $this->eventCalls();
        $this->assertCount(1, $calls);
        $body = $calls[0]->data();
        $this->assertSame($row->id, $body['event_id']);
        $this->assertSame('optifleet.workshop_invoice.recorded', $body['event_key']);
        $this->assertSame($this->nexusTenantId, $body['tenant_id']);
        $this->assertSame('workshop_invoice', $body['data']['aggregate_type']);
        $this->assertSame($row->aggregate_id, $body['data']['aggregate_id']);
        $this->assertSame('1500000.00', $body['data']['payload']['total_amount'], 'money stays a decimal string');
        $this->assertSame($row->correlation['work_order_id'], $body['correlation_id']);
        $this->assertTrue($calls[0]->hasHeader('Authorization', 'Bearer tok-1'));

        $tokenRequest = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth/token'))->first()[0];
        $this->assertStringContainsString('event.write', $tokenRequest['scope']);

        // Nothing more to send.
        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'failed' => 0], $this->relay());
        $this->assertCount(1, $this->eventCalls());
    }

    public function test_events_of_a_tenant_that_is_not_linked_are_left_alone(): void
    {
        $row = $this->record($this->unlinked);

        $this->relay();

        $this->assertCount(0, $this->eventCalls());
        $this->assertSame('PENDING', $row->fresh()->status);
        $this->assertSame(0, $row->fresh()->attempts);
    }

    public function test_a_server_error_is_retried_later_with_backoff(): void
    {
        $row = $this->record($this->linked);
        $this->answers = [[503, 'down']];

        $this->assertSame(1, $this->relay()['retrying']);
        $this->assertSame('PENDING', $row->fresh()->status);
        $this->assertSame('HTTP 503', $row->fresh()->last_error);

        // Too soon: backoff.
        $this->relay();
        $this->assertCount(1, $this->eventCalls());

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->relay()['delivered']);
        $this->assertCount(2, $this->eventCalls());
        $this->assertSame('DELIVERED', $row->fresh()->status);
        $this->assertSame(2, $row->fresh()->attempts);
        // The same event id both times, so OptiNexus can never end up with two.
        $this->assertSame($this->eventCalls()[0]->data()['event_id'], $this->eventCalls()[1]->data()['event_id']);
    }

    public function test_an_unregistered_event_type_waits_for_the_catalog_and_then_goes_through(): void
    {
        $row = $this->record($this->linked);
        $this->answers = [[422, ['error' => ['code' => 'EVENT_INVALID']]]];

        $this->assertSame(1, $this->relay()['retrying']);
        $this->assertStringContainsString('Event Catalog', $row->fresh()->last_error);
        $this->assertSame('PENDING', $row->fresh()->status);

        $this->travel(2)->minutes();
        $this->assertSame(1, $this->relay()['delivered']);
    }

    public function test_after_too_many_attempts_the_event_is_parked_and_can_be_requeued(): void
    {
        $row = $this->record($this->linked);
        $this->answers = array_fill(0, 5, [500, 'boom']);

        for ($i = 0; $i < 5; $i++) {
            $this->relay();
            $this->travel(20)->minutes(); // longer than the 16 minute wait after the fourth attempt
        }
        $this->assertSame('FAILED', $row->fresh()->status);

        $this->assertSame(0, Artisan::call('optinexus:relay-events', ['--retry-failed' => true]));
        $this->assertSame('DELIVERED', $row->fresh()->status);
    }

    public function test_a_refusal_that_retrying_cannot_fix_fails_at_once(): void
    {
        $schema = $this->record($this->linked, 'workshop_invoice.recorded');
        $denied = $this->record($this->linked, 'maintenance_memo.paid');
        $this->answers = [
            [422, ['error' => ['code' => 'EVENT_SCHEMA_INVALID']]],
            [403, ['error' => ['code' => 'EVENT_SOURCE_DENIED']]],
        ];

        $this->assertSame(['delivered' => 0, 'retrying' => 0, 'failed' => 2], $this->relay());

        $this->assertSame('FAILED', $schema->fresh()->status);
        $this->assertStringContainsString('EVENT_SCHEMA_INVALID', $schema->fresh()->last_error);
        $this->assertSame('FAILED', $denied->fresh()->status);
        $this->assertSame(1, $schema->fresh()->attempts);
    }

    public function test_an_expired_token_is_renewed_once_and_the_event_still_arrives(): void
    {
        $row = $this->record($this->linked);
        $this->answers = [[401, ['message' => 'Unauthenticated']]];

        $this->assertSame(1, $this->relay()['delivered']);

        $this->assertCount(2, $this->eventCalls());
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/oauth/token')));
        $this->assertSame('DELIVERED', $row->fresh()->status);
    }

    public function test_when_optinexus_is_unreachable_the_rest_of_the_batch_is_not_attempted(): void
    {
        $first = $this->record($this->linked, 'workshop_invoice.recorded');
        $second = $this->record($this->linked, 'maintenance_memo.billed');
        $this->answers = [new ConnectionException('timeout')];

        $result = $this->relay();

        $this->assertSame(1, $result['retrying']);
        $this->assertSame(1, $this->eventHits, 'the second event is not attempted');
        $this->assertSame(1, $first->fresh()->attempts + $second->fresh()->attempts);
    }

    public function test_the_command_does_nothing_unless_both_switches_are_on(): void
    {
        $row = $this->record($this->linked);
        config(['optinexus.events.enabled' => false]);

        $this->assertSame(0, Artisan::call('optinexus:relay-events'));
        $this->assertStringContainsString('disabled', Artisan::output());
        $this->assertCount(0, $this->eventCalls());
        $this->assertSame('PENDING', $row->fresh()->status);

        config(['optinexus.events.enabled' => true]);
        $this->assertSame(0, Artisan::call('optinexus:relay-events'));
        $this->assertSame('DELIVERED', $row->fresh()->status);
    }

    public function test_a_tenant_filter_only_relays_that_tenant(): void
    {
        $otherNexus = (string) Str::uuid();
        $other = $this->makeTenant(['optinexus_tenant_id' => $otherNexus]);
        $mine = $this->record($this->linked);
        $theirs = $this->record($other);

        Artisan::call('optinexus:relay-events', ['--tenant' => $this->linked->id]);

        $this->assertSame('DELIVERED', $mine->fresh()->status);
        $this->assertSame('PENDING', $theirs->fresh()->status);
    }
}
