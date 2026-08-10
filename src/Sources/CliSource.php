<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Sources;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\AuthenticationFailed;
use AlexHackney\Doppler\Exceptions\RateLimited;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Factory as ProcessFactory;
use Throwable;

/**
 * Fetches secrets by shelling out to the doppler binary.
 *
 * Not the default. This exists for boxes where egress to api.doppler.com is only permitted
 * for the CLI, or where the CLI's own auth is already the established provisioning path,
 * and as an escape hatch if the API driver ever misbehaves.
 *
 * Still requests JSON rather than env, for the same reason the API driver does: this
 * package renders its own output, because Doppler's env rendering does not match
 * phpdotenv's grammar.
 *
 * --no-file is required, or the CLI writes to a file instead of stdout.
 *
 * The token goes through the environment rather than an argument, because an argument is
 * visible in ps to every user on the box.
 */
final class CliSource implements SecretSource
{
    public function __construct(
        private readonly ProcessFactory $process,
        private readonly string $binary = 'doppler',
        private readonly int $timeout = 30,
    ) {}

    public function name(): string
    {
        return 'cli';
    }

    /**
     * @return array<string, string>
     */
    public function fetch(Credential $credential): array
    {
        if ($credential->needsScope()) {
            throw AuthenticationFailed::scopeRequired($credential->type->label());
        }

        $result = $this->run($credential);

        if (! $result->successful()) {
            throw $this->classify($result, $credential);
        }

        return $this->decode($result->output());
    }

    private function run(Credential $credential): ProcessResult
    {
        $command = [$this->binary, 'secrets', 'download', '--no-file', '--format', 'json'];

        if ($credential->project !== null && $credential->config !== null) {
            $command[] = '--project';
            $command[] = $credential->project;
            $command[] = '--config';
            $command[] = $credential->config;
        }

        try {
            // newPendingProcess() rather than the factory's __call proxy, which is
            // untyped. It carries the fake handlers through, so Process::fake() still
            // intercepts this.
            return $this->process
                ->newPendingProcess()
                ->timeout($this->timeout)
                ->env(['DOPPLER_TOKEN' => $credential->reveal()])
                ->run($command);
        } catch (Throwable $e) {
            throw SourceUnavailable::network('cli', $e->getMessage(), $e);
        }
    }

    /**
     * Map the CLI's stderr onto the same three failure types the API driver produces, so
     * the exit codes and the soft-fail decision do not depend on which driver is in use.
     */
    private function classify(ProcessResult $result, Credential $credential): Throwable
    {
        $stderr = trim($result->errorOutput());
        $haystack = strtolower($stderr);

        if ($this->mentionsAny($haystack, ['unauthorized', 'invalid token', 'authentication', 'forbidden', 'token is not valid'])) {
            return AuthenticationFailed::rejected(401, $credential->redacted(), $credential->source);
        }

        if ($this->mentionsAny($haystack, ['rate limit', 'too many requests', '429'])) {
            return RateLimited::make(null);
        }

        if ($this->isMissingBinary($result, $haystack)) {
            return SourceUnavailable::network(
                'cli',
                sprintf(
                    'the %s binary was not found on PATH. Install the Doppler CLI, or switch to the api driver, which needs no binary.',
                    $this->binary,
                ),
            );
        }

        return SourceUnavailable::network(
            'cli',
            sprintf('exit code %d%s', $result->exitCode() ?? -1, $stderr === '' ? '' : ': '.$stderr),
        );
    }

    private function isMissingBinary(ProcessResult $result, string $stderr): bool
    {
        return $result->exitCode() === 127
            || $this->mentionsAny($stderr, ['command not found', 'no such file or directory', 'not recognized']);
    }

    /**
     * @param  list<string>  $needles
     */
    private function mentionsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function decode(string $output): array
    {
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw SourceUnavailable::malformedResponse('the CLI did not return a JSON object');
        }

        $secrets = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

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
