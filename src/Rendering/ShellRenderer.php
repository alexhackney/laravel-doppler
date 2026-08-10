<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;

/**
 * Renders a file meant to be `source`d by a POSIX shell.
 *
 * Every value is single-quoted, which in POSIX shell is fully literal: no expansion, no
 * escape processing, and a newline inside the quotes is legal. That last point is why this
 * is the one non-laravel target with no multi-line refusal.
 *
 * The single-quote escape is the classic POSIX dance: close the quote, emit an escaped
 * quote, reopen. `it's` becomes `'it'\''s'`.
 *
 * `export` is emitted so that a sourced file actually populates the environment of child
 * processes rather than only the sourcing shell.
 */
final class ShellRenderer implements Renderer
{
    public function name(): string
    {
        return 'shell';
    }

    /**
     * @param  array<string, string>  $secrets
     */
    public function render(array $secrets): string
    {
        ksort($secrets, SORT_STRING);

        $lines = [];

        foreach ($secrets as $key => $value) {
            $lines[] = 'export '.$key.'='.$this->quote($value);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * POSIX single-quote a value.
     */
    public function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }

    /**
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        $result = [];

        // Values may span lines, so this walks the content rather than splitting on
        // newlines the way the single-line grammars can.
        $offset = 0;
        $length = strlen($content);

        while ($offset < $length) {
            // Skip whitespace and comment lines between assignments.
            while ($offset < $length && ($content[$offset] === "\n" || $content[$offset] === "\r" || $content[$offset] === ' ' || $content[$offset] === "\t")) {
                $offset++;
            }

            if ($offset >= $length) {
                break;
            }

            if ($content[$offset] === '#') {
                $newline = strpos($content, "\n", $offset);

                if ($newline === false) {
                    break;
                }

                $offset = $newline + 1;

                continue;
            }

            if (! preg_match('/\G(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=/', $content, $m, 0, $offset)) {
                throw RoundTripFailed::unparseable('expected an assignment');
            }

            $key = $m[1];
            $offset += strlen($m[0]);

            [$value, $offset] = $this->readValue($content, $offset);

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Read one shell value starting at $offset, returning the value and the new offset.
     *
     * @return array{0: string, 1: int}
     */
    private function readValue(string $content, int $offset): array
    {
        $length = strlen($content);

        if ($offset >= $length) {
            return ['', $offset];
        }

        if ($content[$offset] !== "'") {
            // Unquoted: read to end of line.
            $newline = strpos($content, "\n", $offset);
            $end = $newline === false ? $length : $newline;

            return [substr($content, $offset, $end - $offset), $end];
        }

        $offset++;
        $value = '';

        while ($offset < $length) {
            if ($content[$offset] !== "'") {
                $value .= $content[$offset++];

                continue;
            }

            // A closing quote. If it is immediately followed by \'' then this is the
            // POSIX escape for a literal single quote and the value continues.
            if (substr($content, $offset, 4) === "'\\''") {
                $value .= "'";
                $offset += 4;

                continue;
            }

            return [$value, $offset + 1];
        }

        throw RoundTripFailed::unparseable('unterminated single quote');
    }
}
