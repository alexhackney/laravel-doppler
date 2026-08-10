<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Rendering;

use AlexHackney\Doppler\Exceptions\RoundTripFailed;
use Dotenv\Dotenv;
use Throwable;

/**
 * Renders for phpdotenv, which is what Laravel loads.
 *
 * The algorithm, and why. Verified against phpdotenv 5.6.4; see
 * docs/phpdotenv-grammar.md for the probe output.
 *
 *   value === ''                  ->  K=
 *   contains LF, CR, or a quote   ->  double-quote, escaping \ then " then $
 *   otherwise                     ->  single-quote
 *
 * Single quotes are the default because they are the only fully literal form in this
 * grammar: no interpolation, no escape processing, nothing to get wrong. A value
 * containing #, a space, $$, ${, a double quote, a backslash, a tab, or UTF-8 all survive
 * a single-quoted round trip byte for byte.
 *
 * Unquoted values are never emitted. `K=has space` is a hard parse error that kills the
 * whole file, and `K=has#hash` silently truncates at the hash with no preceding whitespace
 * required. That is the env-no-quotes bug class this package exists to close.
 *
 * The double-quoted branch handles exactly the two cases single quotes cannot express: a
 * value containing a single quote, and a value containing a newline. Both require escaping
 * $ as well, because ${ interpolates inside double quotes at load time.
 */
final class LaravelRenderer implements Renderer
{
    public function name(): string
    {
        return 'laravel';
    }

    /**
     * @param  array<string, string>  $secrets
     */
    public function render(array $secrets): string
    {
        ksort($secrets, SORT_STRING);

        $lines = [];

        foreach ($secrets as $key => $value) {
            $lines[] = $key.'='.$this->quote($value);
        }

        return $lines === [] ? '' : implode("\n", $lines)."\n";
    }

    /**
     * Quote a single value for the phpdotenv grammar.
     */
    public function quote(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if ($this->needsDoubleQuotes($value)) {
            return '"'.$this->escapeForDoubleQuotes($value).'"';
        }

        return "'".$value."'";
    }

    /**
     * Single quotes cannot express a value containing a single quote, and a real newline
     * inside single quotes is a hard parse error ("missing closing quote").
     */
    private function needsDoubleQuotes(string $value): bool
    {
        return str_contains($value, "'")
            || str_contains($value, "\n")
            || str_contains($value, "\r");
    }

    /**
     * Escape for the double-quoted form.
     *
     * Order is load-bearing: the backslash must be escaped first, or the escapes added
     * afterwards get mangled by the pass that would have escaped their own backslashes.
     *
     * The $ escape is what stops ${ interpolating at load time. Verified: phpdotenv honours
     * \$ inside double quotes and yields a literal $. The escape must go on the dollar and
     * never on the brace, because "$\{" is an "unexpected escape sequence" parse error.
     *
     * CR and LF are emitted as \r and \n escapes rather than as real characters. Real
     * newlines inside double quotes do parse, but the escaped form keeps every entry on one
     * line, which keeps diffs readable and keeps a stray value from resembling a new key.
     */
    private function escapeForDoubleQuotes(string $value): string
    {
        return str_replace(
            ['\\', '"', '$', "\r", "\n"],
            ['\\\\', '\\"', '\\$', '\\r', '\\n'],
            $value,
        );
    }

    /**
     * Load content back exactly as Laravel's bootstrapper does.
     *
     * Using the full Dotenv stack rather than Dotenv\Parser\Parser is deliberate and is the
     * single most important decision in this class. The parser does NOT interpolate:
     * parsing `K=pre${BASE}post` returns the literal text. Interpolation happens later, in
     * the loader. A guard built on the parser alone would happily pass a double-quoted ${
     * value that then corrupts silently at boot, which is the exact failure this package
     * exists to prevent.
     *
     * A fresh ArrayAdapter per call isolates the load: no ambient process environment can
     * satisfy a reference, and an immutable repository means nothing already set wins over
     * what is in the content.
     *
     * @return array<string, string>
     */
    public function parse(string $content): array
    {
        try {
            // Dotenv::parse builds a StringStore over a fresh, adapter-isolated
            // ArrayAdapter repository and runs the full Parser plus Loader path. The
            // Loader is where interpolation happens, so this models the real boot
            // faithfully while remaining sealed off from the ambient environment.
            $loaded = Dotenv::parse($content);
        } catch (Throwable $e) {
            throw RoundTripFailed::unparseable($e->getMessage());
        }

        $result = [];

        foreach ($loaded as $key => $value) {
            // A bare `K` with no `=` yields null. Doppler never produces one, but rendered
            // content is untrusted input to this method, so it is normalised rather than
            // allowed to become a type error downstream.
            $result[$key] = $value ?? '';
        }

        return $result;
    }
}
