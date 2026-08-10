<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Sources;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\RateLimited;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Fetches secrets from the Doppler HTTP API.
 *
 * The default driver, chosen over shelling out to the CLI for four concrete reasons:
 *
 *   - No binary to provision. Every hand-rolled copy of this sync exits if `which doppler`
 *     fails, which is a provisioning dependency on every box, every container image, and
 *     every CI runner.
 *   - format=json rather than format=env means this package owns its own escaping. Writing
 *     Doppler's rendered env text verbatim means trusting an unaudited escaping
 *     implementation to match phpdotenv's grammar exactly, and it does not: env-no-quotes
 *     silently truncates any value containing a hash.
 *   - Http::fake() is a far better test surface than faking a process.
 *   - Rate limit headers become visible and actionable.
 */
final class ApiSource implements SecretSource
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl = 'https://api.doppler.com/v3',
        private readonly int $timeout = 15,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 500,
    ) {}

    public function name(): string
    {
        return 'api';
    }

    /**
     * @return array<string, string>
     */
    public function fetch(Credential $credential): array
    {
        $this->assertScoped($credential);

        $response = $this->request($credential);

        $this->assertSuccessful($response, $credential);

        return $this->decode($response);
    }

    /**
     * Personal and service-account tokens address a whole workspace, so they cannot resolve
     * a config on their own. Catching that here produces "this is a personal token, so
     * --project and --config are required" instead of a Doppler error about a missing
     * config, which is a materially better thing to read at 2am.
     */
    private function assertScoped(Credential $credential): void
    {
        if ($credential->needsScope()) {
            throw AuthenticationFailed::scopeRequired($credential->type->label());
        }
    }

    /**
     * Issue the request, retrying only what is worth retrying.
     *
     * The retry policy is written out as a loop rather than handed to the HTTP client's
     * retry() helper, because the policy is status-dependent and retry() only sees
     * exceptions. What matters here:
     *
     *   429 and 5xx  retried. Transient, and Doppler's rate limit is roughly 240/min,
     *                which a per-minute timer across a fleet will find.
     *   connection    retried. A dropped packet is not a configuration problem.
     *   401 and 403   NEVER retried. A revoked token does not become valid on the second
     *                 attempt, and retrying burns rate limit a healthy box may need.
     */
    private function request(Credential $credential): Response
    {
        $query = ['format' => 'json'];

        // Service tokens are already scoped to exactly one config. Sending project and
        // config alongside one is not merely redundant: Doppler rejects the combination.
        if ($credential->project !== null && $credential->config !== null) {
            $query['project'] = $credential->project;
            $query['config'] = $credential->config;
        }

        $attempts = max($this->retries, 0) + 1;
        $lastConnectionError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http
                    ->withToken($credential->reveal())
                    ->accept('application/json')
                    ->timeout($this->timeout)
                    ->get($this->baseUrl.'/configs/config/secrets/download', $query);
            } catch (ConnectionException $e) {
                $lastConnectionError = $e;

                $this->pause($attempt, $attempts);

                continue;
            }

            if (! $this->isRetryable($response->status()) || $attempt === $attempts) {
                return $response;
            }

            $this->pause($attempt, $attempts);
        }

        throw SourceUnavailable::network(
            'api',
            $lastConnectionError?->getMessage() ?? 'the request failed repeatedly',
            $lastConnectionError,
        );
    }

    private function isRetryable(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    private function pause(int $attempt, int $attempts): void
    {
        if ($attempt >= $attempts || $this->retryDelayMs <= 0) {
            return;
        }

        // Linear backoff. Exponential would be better against a sustained outage, but this
        // runs inside a deploy where total wall clock matters more than politeness.
        usleep($this->retryDelayMs * 1000 * $attempt);
    }

    private function assertSuccessful(Response $response, Credential $credential): void
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw AuthenticationFailed::rejected($status, $credential->redacted(), $credential->source);
        }

        if ($status === 429) {
            throw RateLimited::make($this->retryAfter($response));
        }

        if ($status >= 500) {
            throw SourceUnavailable::serverError($status);
        }

        if ($status >= 400) {
            // A 404 on a project or config name, or a 422 on a malformed request. Doppler
            // answered and said no, which is a defect rather than weather, so it must not
            // be soft-failable. AuthenticationFailed carries the right exit code for
            // "stop, this will not fix itself".
            throw new AuthenticationFailed(
                sprintf(
                    'Doppler rejected the request (HTTP %d). %s',
                    $status,
                    $this->errorMessage($response) ?? 'Check the project and config names.',
                ),
                $status,
            );
        }
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('retry-after');

        return is_numeric($header) ? (int) $header : null;
    }

    /**
     * Pull Doppler's own error text out of the body, if it supplied any.
     *
     * Doppler returns {"messages": ["..."], "success": false} on a rejection.
     */
    private function errorMessage(Response $response): ?string
    {
        try {
            $body = $response->json();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($body)) {
            return null;
        }

        $messages = $body['messages'] ?? null;

        if (is_array($messages) && $messages !== []) {
            return implode(' ', array_map('strval', $messages));
        }

        return is_string($body['message'] ?? null) ? $body['message'] : null;
    }

    /**
     * @return array<string, string>
     */
    private function decode(Response $response): array
    {
        try {
            $decoded = $response->json();
        } catch (Throwable $e) {
            throw SourceUnavailable::malformedResponse('the body was not valid JSON');
        }

        if (! is_array($decoded)) {
            throw SourceUnavailable::malformedResponse('expected a JSON object of key/value pairs');
        }

        $secrets = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Doppler emits a key it holds but has not set as an empty string, and null
            // has been seen for computed secrets. Both normalise to empty, which is what
            // the required rule then tests for. Scalars are cast because a numeric-looking
            // secret can decode as int.
            $secrets[$key] = match (true) {
                $value === null => '',
                is_string($value) => $value,
                is_scalar($value) => (string) $value,
                default => throw SourceUnavailable::malformedResponse(
                    sprintf('the value for %s was not a scalar', $key),
                ),
            };
        }

        return $secrets;
    }
}
