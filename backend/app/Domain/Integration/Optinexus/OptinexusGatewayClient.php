<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OptiFleet's client of the OptiNexus API Gateway. One platform-level
 * service account acts for each tenant by naming it in X-Tenant-Id; the
 * gateway only honours tenants subscribed to OptiFleet.
 */
class OptinexusGatewayClient
{
    private const SCOPES = 'gateway.fleet.write gateway.fleet.read gateway.telematics.read event.write';

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
        $send = fn () => Http::withToken($this->token())
            ->acceptJson()
            ->timeout(config('optinexus.gateway.timeout_seconds'))
            ->post(config('optinexus.base_url').'/api/v1/events', $envelope);

        $response = $send();
        if ($response->status() === 401) {
            Cache::forget('optinexus.gateway.token');
            $response = $send();
        }

        return $response;
    }

    private function request(string $optinexusTenantId): PendingRequest
    {
        return Http::withToken($this->token())
            ->withHeaders(['X-Tenant-Id' => $optinexusTenantId, 'X-Correlation-Id' => (string) Str::uuid()])
            ->acceptJson()
            ->timeout(config('optinexus.gateway.timeout_seconds'))
            ->baseUrl(config('optinexus.base_url').'/api/gateway/v1');
    }

    private function token(): string
    {
        return Cache::remember('optinexus.gateway.token', 3000, function () {
            $response = Http::asForm()->timeout(15)->post(config('optinexus.base_url').'/api/v1/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('optinexus.gateway.client_id'),
                'client_secret' => config('optinexus.gateway.client_secret'),
                'scope' => self::SCOPES,
            ]);

            if (! $response->ok() || ! $response->json('access_token')) {
                throw new RuntimeException('OptiNexus gateway authentication failed.');
            }

            return $response->json('access_token');
        });
    }
}
