<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Exceptions;

use AlexHackney\Doppler\Support\ExitCode;
use Throwable;

/**
 * The atomic write could not complete.
 *
 * Every path that throws this leaves the previous target file exactly as it was. That
 * guarantee is the point of the writer, so any new throw site has to preserve it.
 */
final class WriteFailed extends DopplerException
{
    public function __construct(
        string $message,
        public readonly ?string $path = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function exitCode(): ExitCode
    {
        // A failed write is a local, non-transient problem: a full disk, a read-only
        // mount, a permissions error. Reported as source-unavailable would be a lie, so
        // it takes the validation code, which is the generic "stop, this is a defect".
        return ExitCode::ValidationFailed;
    }

    public static function directoryNotWritable(string $path): self
    {
        return new self(
            sprintf(
                'Cannot write beside %s: the containing directory is not writable by the current user. '.
                'The existing file was left untouched.',
                $path,
            ),
            $path,
        );
    }

    public static function couldNotLock(string $path): self
    {
        return new self(
            sprintf(
                'Could not acquire the sync lock for %s. Another sync is running on this box. '.
                'The existing file was left untouched.',
                $path,
            ),
            $path,
        );
    }

    public static function tempWriteFailed(string $path): self
    {
        return new self(
            sprintf('Failed writing the temporary file beside %s. The existing file was left untouched.', $path),
            $path,
        );
    }

    public static function renameFailed(string $path): self
    {
        return new self(
            sprintf('Failed moving the rendered file into place at %s.', $path),
            $path,
        );
    }
}
