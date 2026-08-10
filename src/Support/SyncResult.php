<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Support;

use AlexHackney\Doppler\Hooks\HookResult;
use AlexHackney\Doppler\Validation\Problem;

/**
 * What a sync did.
 *
 * Deliberately holds no secret values, so it is safe to log, serialise into a job payload,
 * or return from a health check endpoint.
 */
final class SyncResult
{
    /**
     * @param  bool  $written  Whether the target file was actually replaced.
     * @param  bool  $unchanged  Whether the render was byte-identical to what was there.
     * @param  bool  $softFailed  Whether an unreachable source was downgraded to success.
     * @param  string|null  $softFailReason  Why, when it was.
     * @param  int|null  $keptFileAge  Age in seconds of the file kept on a soft-fail.
     * @param  list<Problem>  $problems  Validation problems, forced or otherwise.
     * @param  list<string>  $overlaidKeys  Keys whose value came from the overlay file.
     * @param  list<string>  $preservedKeys  Keys carried forward from the existing file.
     * @param  list<HookResult>  $hooks
     */
    public function __construct(
        public readonly bool $written,
        public readonly string $path,
        public readonly ExitCode $exitCode = ExitCode::Success,
        public readonly bool $unchanged = false,
        public readonly bool $softFailed = false,
        public readonly ?string $softFailReason = null,
        public readonly ?int $keptFileAge = null,
        public readonly ?Diff $diff = null,
        public readonly array $problems = [],
        public readonly array $overlaidKeys = [],
        public readonly array $preservedKeys = [],
        public readonly array $hooks = [],
        public readonly int $secretCount = 0,
        public readonly string $driver = '',
        public readonly string $target = 'laravel',
        public readonly ?string $renderedContent = null,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === ExitCode::Success;
    }

    public function hasProblems(): bool
    {
        return $this->problems !== [];
    }

    /**
     * A one-line summary for logs.
     */
    public function summary(): string
    {
        if ($this->softFailed) {
            return sprintf(
                'kept the existing %s (%s), source unreachable',
                basename($this->path),
                $this->describeAge(),
            );
        }

        if ($this->unchanged) {
            return sprintf('%s already current, %d secrets, nothing written', basename($this->path), $this->secretCount);
        }

        if ($this->written) {
            return sprintf(
                'wrote %s, %d secrets%s',
                basename($this->path),
                $this->secretCount,
                $this->diff !== null && $this->diff->hasDrift()
                    ? sprintf(' (%d changed)', $this->diff->driftCount())
                    : '',
            );
        }

        return sprintf('%s: nothing written', $this->exitCode->label());
    }

    private function describeAge(): string
    {
        if ($this->keptFileAge === null) {
            return 'age unknown';
        }

        $minutes = (int) floor($this->keptFileAge / 60);

        if ($minutes < 60) {
            return sprintf('%d minute%s old', $minutes, $minutes === 1 ? '' : 's');
        }

        $hours = (int) floor($minutes / 60);

        if ($hours < 48) {
            return sprintf('%d hour%s old', $hours, $hours === 1 ? '' : 's');
        }

        return sprintf('%d days old', (int) floor($hours / 24));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'written' => $this->written,
            'unchanged' => $this->unchanged,
            'soft_failed' => $this->softFailed,
            'soft_fail_reason' => $this->softFailReason,
            'path' => $this->path,
            'exit_code' => $this->exitCode->value,
            'secret_count' => $this->secretCount,
            'driver' => $this->driver,
            'target' => $this->target,
            'added' => $this->diff !== null ? $this->diff->added : [],
            'removed' => $this->diff !== null ? $this->diff->removed : [],
            'changed' => $this->diff !== null ? $this->diff->changed : [],
            'overlaid_keys' => $this->overlaidKeys,
            'preserved_keys' => $this->preservedKeys,
            'problems' => array_map(fn (Problem $p): array => $p->toArray(), $this->problems),
        ];
    }
}
