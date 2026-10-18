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
 * as they are for the caller to interpret against the documented contract. A caller that knows a 429 carries a documented business
 * refusal (the invitation cooldown and rate limit) names its codes: such an answer is returned as it is, with the `Retry-After` the
 * person has to wait, instead of blocking the request or being reported as an outage.
 */
class NexusApiClient
{
    public function __construct(private readonly NexusTokenProvider $tokens) {}

    /**
     * @param  array<string, mixed>  $payload  query string for GET/DELETE, JSON body otherwise
     * @param  list<string>  $answerRateLimitCodes  error codes whose 429 answer is returned (with `retry_after`) rather than waited for
     * @return array{status: int, data: mixed, meta: mixed, error: ?array{code: ?string, message: ?string, details: mixed}, correlation_id: string, retry_after: ?int}
     *
     * @throws GovernanceUnavailableException
     */
    public function send(string $scopes, string $method, string $path, array $payload = [], array $answerRateLimitCodes = []): array
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
                if (in_array($this->errorCode($response), $answerRateLimitCodes, true)) {
                    return $this->answer($response, $correlationId);
                }

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

            return $this->answer($response, $correlationId);
        }
    }

    /** @return array{status: int, data: mixed, meta: mixed, error: ?array{code: ?string, message: ?string, details: mixed}, correlation_id: string, retry_after: ?int} */
    private function answer(Response $response, string $correlationId): array
    {
        $json = $response->json();
        $error = is_array($json) && isset($json['error']) && is_array($json['error']) ? [
            'code' => $json['error']['code'] ?? null,
            'message' => $json['error']['message'] ?? null,
            'details' => $json['error']['details'] ?? null,
        ] : null;
        $retryAfter = $response->header('Retry-After');

        return [
            'status' => $response->status(),
            'data' => is_array($json) ? ($json['data'] ?? null) : null,
            'meta' => is_array($json) ? ($json['meta'] ?? null) : null,
            'error' => $error,
            'correlation_id' => $correlationId,
            // Seconds only (an HTTP-date is not used by OptiNexus); bounded so a faulty value cannot freeze a screen.
            'retry_after' => $response->status() === 429 && is_numeric($retryAfter) ? max(1, min(86400, (int) $retryAfter)) : null,
        ];
    }

    private function errorCode(Response $response): ?string
    {
        $code = $response->json('error.code');

        return is_string($code) ? $code : null;
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
