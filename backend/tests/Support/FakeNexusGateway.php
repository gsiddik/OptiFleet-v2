<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * The API Gateway part of the fake OptiNexus (OptiNexus docs/integration/INTEGRATION_GUIDE.md section 3): the vehicle directory the
 * fleet application publishes and the odometer feed it reads with a cursor. Pages are served in the order they were queued, one per
 * request, the last one repeating; `fail()` makes every gateway call answer with a documented error envelope.
 */
class FakeNexusGateway
{
    /** @var array<int, array{items: array<int, mixed>, next_cursor: string}> */
    public array $pages = [];

    public int $served = 0;

    /** @var array<int, array{nexus_tenant: ?string, cursor: ?string, limit: ?string}> */
    public array $feedRequests = [];

    /** @var array<int, array{nexus_tenant: ?string, vehicles: array}> */
    public array $published = [];

    /** @var array{status: int, code: string}|null */
    public ?array $error = null;

    /** The gateway cannot be reached at all (the rest of the fake OptiNexus still answers). */
    public bool $down = false;

    /** The next feed answer, verbatim, instead of a queued page (for answers the real gateway should never give). */
    public mixed $rawFeedAnswer = null;

    public function page(array $items, string|int $nextCursor): self
    {
        $this->pages[] = ['items' => $items, 'next_cursor' => (string) $nextCursor];

        return $this;
    }

    public function fail(string $code, int $status): self
    {
        $this->error = ['status' => $status, 'code' => $code];

        return $this;
    }

    public function handle(Request $request, string $path, array $query, array $body)
    {
        if ($this->down) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        }
        if ($this->error !== null) {
            return Http::response(['success' => false, 'error' => ['code' => $this->error['code'], 'message' => 'refused', 'details' => null]], $this->error['status']);
        }
        $nexusTenant = $request->header('X-Tenant-Id')[0] ?? null;

        if ($path === '/api/gateway/v1/fleet/vehicles' && $request->method() === 'PUT') {
            $this->published[] = ['nexus_tenant' => $nexusTenant, 'vehicles' => (array) ($body['vehicles'] ?? [])];

            return Http::response(['success' => true, 'data' => ['upserted' => count((array) ($body['vehicles'] ?? [])), 'linked' => 0]]);
        }

        if ($path === '/api/gateway/v1/telematics/odometer-readings' && $request->method() === 'GET') {
            $this->feedRequests[] = ['nexus_tenant' => $nexusTenant, 'cursor' => $query['cursor'] ?? null, 'limit' => $query['limit'] ?? null];
            if ($this->rawFeedAnswer !== null) {
                return Http::response(['success' => true, 'data' => $this->rawFeedAnswer]);
            }
            $page = $this->pages === [] ? ['items' => [], 'next_cursor' => (string) ($query['cursor'] ?? 0)] : $this->pages[min($this->served++, count($this->pages) - 1)];

            return Http::response(['success' => true, 'data' => $page]);
        }

        return Http::response(['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'not found', 'details' => null]], 404);
    }
}
