<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use AlexHackney\Doppler\Exceptions\UnsupportedValue;

/**
 * Renders for a systemd EnvironmentFile.
 *
 * The format is not phpdotenv and the differences are not cosmetic:
 *
 *   - There is NO multi-line form. A value containing a newline cannot be expressed at
 *     all, so this renderer refuses rather than emitting something that half-works. A
 *     private key in the config is the common trigger.
 *   - Variable expansion is not performed on the file's own contents, so a bare $ and a
 *     ${ are both safe literals here. That is the opposite of the laravel target.
 *   - Double quotes support C-style escapes; single quotes are fully literal but cannot
 *     contain a single quote, because systemd processes no escapes inside them.
 *
 * Double quotes are the default here rather than single, because escaping inside them is
 * the only mechanism that covers every remaining value shape.
 */
final class SystemdRenderer implements Renderer
{
    public function name(): string
    {
        return 'systemd';
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
            $lines[] = $key.'="'.$this->escape($value).'"';
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

            throw UnsupportedValue::multilineNotSupported($offending, 'systemd');
        }
    }

    /**
     * Escape for systemd's double-quoted form.
     *
     * Backslash first, or the escapes added afterwards get double-escaped by the pass that
     * would have handled their own backslashes.
     */
    private function escape(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        $result = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (! str_contains($trimmed, '=')) {
                throw RoundTripFailed::unparseable(sprintf('line without an assignment: %s', $this->summarise($line)));
            }

            [$key, $raw] = explode('=', $trimmed, 2);

            $result[rtrim($key)] = $this->unquote($raw);
        }

        return $result;
    }

    /**
     * Model systemd's own value handling closely enough to be a meaningful guard.
     */
    private function unquote(string $raw): string
    {
        $raw = trim($raw);

        if (strlen($raw) >= 2 && str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return $this->unescape(substr($raw, 1, -1));
        }

        if (strlen($raw) >= 2 && str_starts_with($raw, "'") && str_ends_with($raw, "'")) {
            // No escape processing inside single quotes.
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    private function unescape(string $value): string
    {
        $out = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            if ($value[$i] !== '\\' || $i + 1 >= $length) {
                $out .= $value[$i];

                continue;
            }

            $next = $value[++$i];

            $out .= match ($next) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '\\' => '\\',
                '"' => '"',
                default => '\\'.$next,
            };
        }

        return $out;
    }

    /**
     * A short, value-blind description of a line, for error messages.
     */
    private function summarise(string $line): string
    {
        $length = strlen($line);

        return $length > 20 ? substr($line, 0, 20).'... ('.$length.' bytes)' : $line;
    }
}
