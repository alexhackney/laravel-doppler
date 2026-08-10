<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Writing;

/**
 * Works out who a written file should belong to.
 *
 * This is not a detail. A sync run as root writes a 0600 file owned by root, php-fpm
 * running as the service user cannot open it, and phpdotenv then loads nothing at all.
 * Every config value silently becomes empty, the app boots, and every request fails in a
 * way that looks nothing like a permissions problem.
 *
 * The search order takes the existing target's owner first, because that is the strongest
 * evidence available of who is meant to own it. composer.json and then the base path are
 * fallbacks for the first-ever sync on a fresh box, where no target exists yet.
 */
final class Ownership
{
    /**
     * @param  list<string>  $candidates  Paths to inspect, in order of preference.
     */
    public function __construct(
        private readonly array $candidates = [],
    ) {}

    /**
     * @return array{uid: int, gid: int, source: string}|null
     */
    public function resolve(): ?array
    {
        foreach ($this->candidates as $path) {
            if ($path === '' || ! file_exists($path)) {
                continue;
            }

            $uid = @fileowner($path);
            $gid = @filegroup($path);

            if ($uid === false || $gid === false) {
                continue;
            }

            return ['uid' => $uid, 'gid' => $gid, 'source' => $path];
        }

        return null;
    }

    /**
     * Apply ownership to a path.
     *
     * Failure is tolerated rather than fatal. On Windows, in a rootless container, and as
     * an unprivileged user changing to a different owner, chown is a no-op or an error,
     * and none of those are reasons to refuse a sync that is otherwise correct. The caller
     * verifies readability afterwards, which is the outcome that actually matters.
     *
     * @return bool Whether ownership was applied.
     */
    public function apply(string $path, int $uid, int $gid): bool
    {
        if (! function_exists('chown') || ! function_exists('chgrp')) {
            return false;
        }

        // Nothing to do when the file already has the intended owner, which is the common
        // case and avoids a pointless failed syscall.
        $currentUid = @fileowner($path);
        $currentGid = @filegroup($path);

        if ($currentUid === $uid && $currentGid === $gid) {
            return true;
        }

        // Only root can change a file's owner. Attempting it as anyone else is guaranteed
        // to fail, and the failure emits a warning that a strict test suite treats as a
        // defect. Checking first turns unavoidable noise into a clean "not applied".
        if (! $this->canChangeOwner()) {
            return false;
        }

        $chowned = @chown($path, $uid);
        $chgrped = @chgrp($path, $gid);

        return $chowned && $chgrped;
    }

    /**
     * Whether this process has the privilege to change a file's owner at all.
     */
    private function canChangeOwner(): bool
    {
        if (! function_exists('posix_geteuid')) {
            // Without POSIX support there is no way to ask, so try and let it fail.
            return true;
        }

        return posix_geteuid() === 0;
    }

    /**
     * Whether the current process can actually read the file it just wrote.
     *
     * This is the check that catches the root-owned 0600 case, and it is worth doing even
     * though the write succeeded: the write succeeding proves nothing about whether the
     * service user can read the result.
     */
    public function isReadable(string $path): bool
    {
        clearstatcache(true, $path);

        return is_readable($path);
    }
}
