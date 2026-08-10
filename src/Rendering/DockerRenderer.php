<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use AlexHackney\Doppler\Exceptions\UnsupportedValue;

/**
 * Renders for `docker run --env-file` and `docker compose env_file`.
 *
 * Docker performs NO quote processing on an env file. Everything after the first equals
 * sign is the value, verbatim, to the end of the line. Feeding it a phpdotenv-quoted file
 * gives every value a pair of literal quote characters wrapped around it, which is the
 * quiet kind of wrong: the app boots, the password is just subtly incorrect.
 *
 * So this renderer emits values raw, and its only job is refusing what the format cannot
 * carry:
 *
 *   - a newline, which would be read as the start of a new assignment
 *   - a leading `#` on the whole line, which docker treats as a comment
 *
 * A key containing `=` is impossible here too, but Doppler keys cannot contain one.
 */
final class DockerRenderer implements Renderer
{
    public function name(): string
    {
        return 'docker';
    }

    /**
     * @param  array<string, string>  $secrets
     */
    public function render(array $secrets): string
    {
        $this->refuseMultiline($secrets);

        ksort($secrets, SORT_STRING);

        $lines = [];

        foreach ($secrets as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string, string>  $secrets
     *
     * @throws UnsupportedValue
     */
    private function refuseMultiline(array $secrets): void
    {
        $offending = [];

        foreach ($secrets as $key => $value) {
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                $offending[] = $key;
            }
        }

        if ($offending !== []) {
            sort($offending, SORT_STRING);

            throw UnsupportedValue::multilineNotSupported($offending, 'docker');
        }
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        $result = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                throw RoundTripFailed::unparseable('line without an assignment');
            }

            [$key, $value] = explode('=', $line, 2);

            // Verbatim. No unquoting, because docker does none.
            $result[$key] = $value;
        }

        return $result;
    }
}
