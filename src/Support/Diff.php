<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Support;

/**
 * A key-level, value-blind comparison of two secret maps.
 *
 * Never exposes a value. Where evidence of a change is genuinely needed, it reports a
 * length and a short hash, which is enough to confirm two boxes disagree without putting
 * a credential into a CI log that half the company can read.
 */
final class Diff
{
    /**
     * @param  list<string>  $added  Keys present in the new map only.
     * @param  list<string>  $removed  Keys present in the old map only.
     * @param  list<string>  $changed  Keys in both whose values differ.
     * @param  list<string>  $unchanged  Keys in both with identical values.
     */
    private function __construct(
        public readonly array $added,
        public readonly array $removed,
        public readonly array $changed,
        public readonly array $unchanged,
    ) {}

    /**
     * @param  array<string, string>  $old
     * @param  array<string, string>  $new
     */
    public static function between(array $old, array $new): self
    {
        $added = [];
        $removed = [];
        $changed = [];
        $unchanged = [];

        foreach ($new as $key => $value) {
            if (! array_key_exists($key, $old)) {
                $added[] = $key;

                continue;
            }

            if ($old[$key] === $value) {
                $unchanged[] = $key;

                continue;
            }

            $changed[] = $key;
        }

        foreach (array_keys($old) as $key) {
            if (! array_key_exists($key, $new)) {
                $removed[] = $key;
            }
        }

        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);
        sort($unchanged, SORT_STRING);

        return new self($added, $removed, $changed, $unchanged);
    }

    public function hasDrift(): bool
    {
        return $this->added !== [] || $this->removed !== [] || $this->changed !== [];
    }

    public function driftCount(): int
    {
        return count($this->added) + count($this->removed) + count($this->changed);
    }

    /**
     * A value-blind fingerprint, for confirming two boxes disagree without printing a
     * secret. Eight hex characters of SHA-256 is plenty to distinguish two values while
     * being far too short to attack.
     */
    public static function fingerprint(string $value): string
    {
        return sprintf('%d bytes, sha256:%s', strlen($value), substr(hash('sha256', $value), 0, 8));
    }

    /**
     * Lines suitable for printing. Keys only.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->added as $key) {
            $lines[] = '+ '.$key.' (added)';
        }

        foreach ($this->removed as $key) {
            $lines[] = '- '.$key.' (removed)';
        }

        foreach ($this->changed as $key) {
            $lines[] = '~ '.$key.' (changed)';
        }

        return $lines;
    }
}
