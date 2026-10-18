<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Governance\NexusTokenProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OptiFleet's client of the OptiNexus API Gateway. One platform-level
 * service account acts for each tenant by naming it in X-Tenant-Id; the
 * gateway only honours tenants subscribed to OptiFleet.
 */
class OptinexusGatewayClient
{
    public function __construct(private readonly NexusTokenProvider $tokens) {}

    /**
     * @param  array<int, array{id: string, registration_number: string, vin: ?string, status: string}>  $vehicles
     * @return array{upserted: int, linked: int}
     */
    public function publishVehicles(string $optinexusTenantId, array $vehicles): array
    {
        return $this->request($optinexusTenantId)->put('/fleet/vehicles', ['vehicles' => $vehicles])->throw()->json('data');
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, next_cursor: string}
     */
    public function readings(string $optinexusTenantId, int $cursor, int $limit): array
    {
        return $this->request($optinexusTenantId)->get('/telematics/odometer-readings', ['cursor' => $cursor, 'limit' => $limit])->throw()->json('data');
    }

    /**
     * Reports one event to OptiNexus (POST /api/v1/events). The answer is returned as is, because the caller
     * decides what each status means for the outbox row. An expired token is renewed once.
     */
    public function publishEvent(array $envelope): Response
    {
        $send = fn () => Http::withToken($this->tokens->token(NexusTokenProvider::SCOPES_GATEWAY))
            ->acceptJson()
            ->timeout(config('optinexus.gateway.timeout_seconds'))
            ->post(config('optinexus.base_url').'/api/v1/events', $envelope);

        $response = $send();
        if ($response->status() === 401) {
            $this->tokens->forget(NexusTokenProvider::SCOPES_GATEWAY);
            $response = $send();
        }

        return $response;
    }

    private function request(string $optinexusTenantId): PendingRequest
    {
        return Http::withToken($this->tokens->token(NexusTokenProvider::SCOPES_GATEWAY))
            ->withHeaders(['X-Tenant-Id' => $optinexusTenantId, 'X-Correlation-Id' => (string) Str::uuid()])
            ->acceptJson()
            ->timeout(config('optinexus.gateway.timeout_seconds'))
            ->baseUrl(config('optinexus.base_url').'/api/gateway/v1');
    }
}
