<?php

namespace App\Domain\Governance;

use App\Domain\Governance\Exceptions\GovernanceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

/**
 * One bounded server-to-server call to the OptiNexus API with OptiFleet's service account. Timeouts are short, a 401 renews the
 * token once, a 429 waits at most `max_retry_after_seconds` once, and everything that is not an answer (connection error, 408,
 * 429 beyond the wait, 5xx) is reported as GovernanceUnavailableException so authorization callers deny. 2xx and 4xx are returned
 * as they are for the caller to interpret against the documented contract.
 */
class NexusApiClient
{
    public function __construct(private readonly NexusTokenProvider $tokens) {}

    /**
     * @param  array<string, mixed>  $payload  query string for GET/DELETE, JSON body otherwise
     * @return array{status: int, data: mixed, meta: mixed, error: ?array{code: ?string, message: ?string, details: mixed}, correlation_id: string}
     *
     * @throws GovernanceUnavailableException
     */
    public function send(string $scopes, string $method, string $path, array $payload = []): array
    {
        $correlationId = (string) Str::uuid();
        $refreshed = false;
        $waited = false;

        while (true) {
            $response = $this->attempt($scopes, $method, $path, $payload, $correlationId, $refreshed);

            if ($response->status() === 401 && ! $refreshed) {
                $this->tokens->forget($scopes);
                $refreshed = true;

                continue;
            }

            if ($response->status() === 429) {
                $retryAfter = (int) $response->header('Retry-After');
                if (! $waited && $retryAfter >= 0 && $retryAfter <= (int) config('optinexus.governance.max_retry_after_seconds')) {
                    $waited = true;
                    Sleep::for(max(1, $retryAfter))->seconds();

                    continue;
                }

                throw new GovernanceUnavailableException('The authorization service is rate limiting this application.', $retryAfter ?: null);
            }

            if ($response->status() >= 500 || $response->status() === 408) {
                throw new GovernanceUnavailableException('The authorization service answered with an error ('.$response->status().').');
            }

            $json = $response->json();
            $error = is_array($json) && isset($json['error']) && is_array($json['error']) ? [
                'code' => $json['error']['code'] ?? null,
                'message' => $json['error']['message'] ?? null,
                'details' => $json['error']['details'] ?? null,
            ] : null;

            return [
                'status' => $response->status(),
                'data' => is_array($json) ? ($json['data'] ?? null) : null,
                'meta' => is_array($json) ? ($json['meta'] ?? null) : null,
                'error' => $error,
                'correlation_id' => $correlationId,
            ];
        }
    }

    private function attempt(string $scopes, string $method, string $path, array $payload, string $correlationId, bool $freshToken): Response
    {
        $token = $this->tokens->token($scopes, $freshToken);
        $url = rtrim((string) config('optinexus.base_url'), '/').'/api/v1/'.ltrim($path, '/');

        try {
            $pending = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-Correlation-Id' => $correlationId])
                ->connectTimeout((int) config('optinexus.governance.connect_timeout_seconds'))
                ->timeout((int) config('optinexus.governance.timeout_seconds'));

            return match (strtoupper($method)) {
                'GET' => $pending->get($url, $payload),
                'DELETE' => $pending->delete($url.($payload ? '?'.http_build_query($payload) : '')),
                'PUT' => $pending->asJson()->put($url, $payload),
                'POST' => $pending->asJson()->post($url, $payload),
                default => throw new \InvalidArgumentException('Unsupported method '.$method),
            };
        } catch (ConnectionException $e) {
            throw new GovernanceUnavailableException('The authorization service could not be reached.', null, $e);
        }
    }
}
