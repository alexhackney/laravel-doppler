<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Writing;

use AlexHackney\Doppler\Exceptions\WriteFailed;

/**
 * Writes rendered content into place without ever leaving a half-written file.
 *
 * The guarantee this class exists to provide: on every failure path, the previous file is
 * byte-identical to what it was before. Any change here has to preserve that.
 *
 * The steps, and the specific bug each one closes:
 *
 *   1. flock a lock file beside the target, so two concurrent deploys cannot interleave
 *      their hooks. rename() already makes the file itself safe; the hooks are what race.
 *   2. Write the temp file IN THE TARGET'S DIRECTORY, never in the system temp dir. A
 *      cross-filesystem rename is not atomic: it degrades to copy-then-unlink, which has a
 *      window where the file is partially written. Container syncs that stage in /tmp and
 *      move onto a bind mount have exactly this bug.
 *   3. chmod 0600 before anything can read it.
 *   4. chown to the intended owner, or a root-owned 0600 file becomes invisible to
 *      php-fpm and every config value silently turns empty.
 *   5. Keep one backup generation at `<target>.backup`, so a bad render can be rolled back
 *      by hand mid-incident without going back to the source. `.backup` rather than `.bak`
 *      because that is the spelling Laravel's own .gitignore already covers.
 *   6. rename() over the target, which is atomic within a filesystem.
 *   7. Verify the result is readable, and report when it is not.
 *
 * Never truncates the target to begin with. A shell redirect (`doppler ... > .env`) creates
 * and truncates before the command on the left even runs, so an unreachable source leaves a
 * zero-byte file and takes the box down. That failure is impossible here by construction.
 */
final class AtomicWriter
{
    /**
     * Matches the `.env.backup` entry in Laravel's own application .gitignore.
     */
    public const BACKUP_SUFFIX = '.backup';

    /**
     * Held for the duration of a write so two concurrent deploys cannot interleave hooks.
     *
     * Deliberately never unlinked: removing a lock file another process is holding open is
     * how a mutex stops being one.
     */
    public const LOCK_SUFFIX = '.lock';

    public function __construct(
        private readonly Ownership $ownership,
        private readonly bool $backup = true,
    ) {}

    /**
     * Write content to path atomically.
     *
     * @throws WriteFailed
     */
    public function write(string $path, string $content): WriteOutcome
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw WriteFailed::directoryNotWritable($path);
        }

        // The no-change short circuit. Skipping the write, the backup and the hooks is
        // what makes this command safe to run on a timer, and it makes "did anything
        // change" answerable without diffing.
        if ($this->isUnchanged($path, $content)) {
            return WriteOutcome::unchanged($path);
        }

        $lock = $this->acquireLock($path);

        try {
            $temp = $this->writeTemporary($path, $content);

            $ownershipApplied = false;
            $ownershipSource = null;

            if (($intended = $this->ownership->resolve()) !== null) {
                $ownershipApplied = $this->ownership->apply($temp, $intended['uid'], $intended['gid']);
                $ownershipSource = $intended['source'];
            }

            $backupPath = $this->backup ? $this->backupExisting($path) : null;

            if (! @rename($temp, $path)) {
                @unlink($temp);

                throw WriteFailed::renameFailed($path);
            }

            return new WriteOutcome(
                written: true,
                path: $path,
                backupPath: $backupPath,
                ownershipApplied: $ownershipApplied,
                ownershipSource: $ownershipSource,
                readable: $this->ownership->isReadable($path),
            );
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Whether the target already holds exactly this content.
     */
    public function isUnchanged(string $path, string $content): bool
    {
        if (! is_file($path) || ! is_readable($path)) {
            return false;
        }

        $existing = @file_get_contents($path);

        return $existing !== false && $existing === $content;
    }

    /**
     * @return resource
     */
    private function acquireLock(string $path)
    {
        $lockPath = $path.self::LOCK_SUFFIX;

        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            throw WriteFailed::directoryNotWritable($path);
        }

        // Non-blocking: a second concurrent sync should fail fast and loudly rather than
        // stack up behind the first and then run its hooks immediately afterwards.
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw WriteFailed::couldNotLock($path);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    private function releaseLock($handle): void
    {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    /**
     * Write the temp file beside the target, with a random name so two runs cannot collide.
     */
    private function writeTemporary(string $path, string $content): string
    {
        $temp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';

        $handle = @fopen($temp, 'x');

        if ($handle === false) {
            throw WriteFailed::tempWriteFailed($path);
        }

        // 0600 before any content lands, so the secrets are never briefly world-readable.
        @chmod($temp, 0600);

        $bytes = @fwrite($handle, $content);

        if ($bytes === false || $bytes !== strlen($content)) {
            @fclose($handle);
            @unlink($temp);

            throw WriteFailed::tempWriteFailed($path);
        }

        // fsync before the rename, so a power loss cannot leave the rename durable while
        // the content it points at is still in the page cache.
        @fflush($handle);

        if (function_exists('fsync')) {
            @fsync($handle);
        }

        @fclose($handle);

        return $temp;
    }

    /**
     * Copy the current target aside, keeping exactly one generation.
     *
     * The suffix is `.backup`, not `.bak`, and that is not cosmetic. Laravel's application
     * skeleton ships a .gitignore listing `.env`, `.env.backup` and `.env.production` with
     * no wildcard, so `.env.bak` — a file holding every previous production secret — was
     * committable in a default application. `.backup` is covered for free.
     */
    private function backupExisting(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $backupPath = $path.self::BACKUP_SUFFIX;

        if (! @copy($path, $backupPath)) {
            // A failed backup must not abort a sync that is otherwise correct and
            // verified. The rendered content is already proven good at this point.
            return null;
        }

        @chmod($backupPath, 0600);

        return $backupPath;
    }
}
