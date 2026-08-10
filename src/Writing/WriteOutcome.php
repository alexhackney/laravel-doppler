<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Writing;

/**
 * What the writer actually did.
 */
final class WriteOutcome
{
    /**
     * @param  bool  $written  False when content was already identical.
     * @param  string|null  $backupPath  Where the previous generation was kept.
     * @param  bool  $ownershipApplied  Whether chown/chgrp succeeded.
     * @param  string|null  $ownershipSource  Which path the intended owner came from.
     * @param  bool  $readable  Whether the current user can read the result.
     */
    public function __construct(
        public readonly bool $written,
        public readonly string $path,
        public readonly ?string $backupPath = null,
        public readonly bool $ownershipApplied = false,
        public readonly ?string $ownershipSource = null,
        public readonly bool $readable = true,
    ) {}

    public static function unchanged(string $path): self
    {
        return new self(written: false, path: $path);
    }
}
