<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Delivers the integration outbox (invoice and memo events) to OptiNexus
 * (POST /api/v1/events), so OptiNexus workflows and notifications can react.
 *
 * PostgreSQL stays the source of truth: a row is only ever moved to DELIVERED
 * after OptiNexus accepted it, and it is sent under its own id as `event_id`,
 * so a retry after a timeout can never create a second event. Only tenants
 * linked to OptiNexus are relayed; other rows stay PENDING untouched.
 *
 * Outcomes
 *  - 200/201: DELIVERED.
 *  - network error, 5xx, 408, 429, 401 and 422 EVENT_INVALID (the event type is not
 *    registered in the OptiNexus catalog yet): stays PENDING and is retried with
 *    exponential backoff (2 min after the first attempt, doubling up to 1 h), until max_attempts.
 *  - any other 4xx (payload does not match the catalog, application not assigned
 *    to the tenant, event id conflict): FAILED at once; fix the cause, then
 *    `optinexus:relay-events --retry-failed`.
 */
class OptinexusEventRelay
{
    public function __construct(private readonly OptinexusGatewayClient $gateway) {}

    /**
     * @return array{delivered: int, retrying: int, failed: int}
     */
    public function relay(?string $tenantId = null): array
    {
        $result = ['delivered' => 0, 'retrying' => 0, 'failed' => 0];
        $maxAttempts = (int) config('optinexus.events.max_attempts');

        $rows = IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->where('status', 'PENDING')
            ->whereIn('tenant_id', Tenant::query()->whereNotNull('optinexus_tenant_id')->select('id'))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereRaw(
                "(last_attempted_at is null or last_attempted_at <= ?::timestamp - (least(60 * power(2, attempts), 3600) * interval '1 second'))",
                [now()->utc()->toDateTimeString()],
            )
            ->orderBy('created_at')
            ->limit((int) config('optinexus.events.batch_size'))
            ->get();

        foreach ($rows as $row) {
            $this->mark($row, ['attempts' => $row->attempts + 1, 'last_attempted_at' => now()]);

            try {
                $response = $this->gateway->publishEvent($this->envelope($row));
            } catch (ConnectionException $e) {
                // OptiNexus is unreachable: stop here instead of hammering it with the rest of the batch.
                $this->retryOrPark($row, 'OptiNexus unreachable: '.Str::limit($e->getMessage(), 300), $maxAttempts, $result);
                break;
            } catch (Throwable $e) {
                $this->retryOrPark($row, Str::limit($e->getMessage(), 500), $maxAttempts, $result);

                continue;
            }

            $status = $response->status();

            if ($response->successful()) {
                $this->mark($row, ['status' => 'DELIVERED', 'delivered_at' => now(), 'last_error' => null]);
                $result['delivered']++;
            } elseif ($status === 422 && $response->json('error.code') === 'EVENT_INVALID') {
                $this->retryOrPark($row, 'OptiNexus does not know this event type yet (register it in the Event Catalog).', $maxAttempts, $result);
            } elseif ($status >= 400 && $status < 500 && ! in_array($status, [401, 408, 429], true)) {
                $this->mark($row, ['status' => 'FAILED', 'last_error' => Str::limit("HTTP {$status}: ".$response->body(), 1000)]);
                $result['failed']++;
            } else {
                $this->retryOrPark($row, "HTTP {$status}", $maxAttempts, $result);
            }
        }

        return $result;
    }

    /**
     * Puts parked events back in the queue (after the cause was fixed).
     */
    public function retryFailed(?string $tenantId = null): int
    {
        return IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->where('status', 'FAILED')
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->update(['status' => 'PENDING', 'attempts' => 0, 'last_attempted_at' => null]);
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(IntegrationOutboxEvent $row): array
    {
        $tenant = Tenant::query()->findOrFail($row->tenant_id);

        return [
            'event_id' => $row->id,
            'event_key' => 'optifleet.'.$row->event_type,
            'event_version' => (string) $row->schema_version,
            'occurred_at' => $row->created_at->toIso8601String(),
            'tenant_id' => $tenant->optinexus_tenant_id,
            'correlation_id' => (string) ($row->correlation['work_order_id'] ?? $row->aggregate_id),
            'data' => [
                'aggregate_type' => Str::snake(class_basename($row->aggregate_type)),
                'aggregate_id' => $row->aggregate_id,
                'correlation' => $row->correlation ?? [],
                'payload' => $row->payload,
            ],
        ];
    }

    /**
     * Delivery bookkeeping goes straight to the table: it is technical state, not a business change worth an
     * audit entry per attempt.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mark(IntegrationOutboxEvent $row, array $attributes): void
    {
        IntegrationOutboxEvent::query()->withoutGlobalScopes()->whereKey($row->id)->update($attributes + ['updated_at' => now()]);
        $row->forceFill($attributes);
    }

    private function retryOrPark(IntegrationOutboxEvent $row, string $error, int $maxAttempts, array &$result): void
    {
        if ($row->attempts >= $maxAttempts) {
            $this->mark($row, ['status' => 'FAILED', 'last_error' => $error]);
            $result['failed']++;

            return;
        }

        $this->mark($row, ['last_error' => $error]);
        $result['retrying']++;
    }
}
