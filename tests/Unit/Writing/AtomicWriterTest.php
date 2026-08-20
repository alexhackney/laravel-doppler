<?php

declare(strict_types=1);

use AlexHackney\Doppler\Exceptions\WriteFailed;
use AlexHackney\Doppler\Writing\AtomicWriter;
use AlexHackney\Doppler\Writing\Ownership;

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/doppler-writer-'.bin2hex(random_bytes(6));
    mkdir($this->dir);
    $this->target = $this->dir.'/.env';
});

afterEach(function () {
    // GLOB_BRACE with a leading-dot pattern, because every file this writer creates
    // (.env, .env.backup, .env.lock) is a dotfile and plain glob('*') misses all of them.
    foreach (glob($this->dir.'/{,.}[!.,]*', GLOB_BRACE) ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($this->dir);
});

function writer(bool $backup = true): AtomicWriter
{
    return new AtomicWriter(new Ownership([]), $backup);
}

it('writes the content', function () {
    $outcome = writer()->write($this->target, "KEY='value'\n");

    expect($outcome->written)->toBeTrue();
    expect(file_get_contents($this->target))->toBe("KEY='value'\n");
});

it('writes with 0600 permissions so secrets are never world-readable', function () {
    writer()->write($this->target, "KEY='value'\n");

    expect(fileperms($this->target) & 0777)->toBe(0600);
});

it('leaves no temporary file behind', function () {
    writer()->write($this->target, "KEY='value'\n");

    expect(glob($this->dir.'/*.tmp'))->toBe([]);
});

it('stages the temporary file beside the target, never in the system temp dir', function () {
    // A cross-filesystem rename is not atomic: it degrades to copy-then-unlink, which has
    // a window where the file is partially written. Staging in /tmp and moving onto a bind
    // mount is exactly that bug.
    //
    // Proof: make the target directory read-only while /tmp stays writable. A writer that
    // staged in /tmp would still produce a temp file; this one cannot proceed at all.
    file_put_contents($this->target, "ORIGINAL='keep'\n");
    chmod($this->dir, 0500);

    try {
        expect(fn () => writer()->write($this->target, "NEW='value'\n"))->toThrow(WriteFailed::class);
        expect(glob(sys_get_temp_dir().'/.env*.tmp'))->toBe([]);
    } finally {
        chmod($this->dir, 0700);
    }
})->skipOnWindows();

it('refuses when the target directory is not writable, leaving the original intact', function () {
    file_put_contents($this->target, "ORIGINAL='keep'\n");
    chmod($this->dir, 0500);

    try {
        expect(fn () => writer()->write($this->target, "NEW='value'\n"))
            ->toThrow(WriteFailed::class);

        expect(file_get_contents($this->target))->toBe("ORIGINAL='keep'\n");
    } finally {
        chmod($this->dir, 0700);
    }
})->skipOnWindows();

it('keeps exactly one backup generation', function () {
    file_put_contents($this->target, "FIRST='1'\n");

    writer()->write($this->target, "SECOND='2'\n");
    expect(file_get_contents($this->target.'.backup'))->toBe("FIRST='1'\n");

    writer()->write($this->target, "THIRD='3'\n");
    expect(file_get_contents($this->target.'.backup'))->toBe("SECOND='2'\n");
});

it('makes the backup 0600 as well', function () {
    file_put_contents($this->target, "FIRST='1'\n");

    writer()->write($this->target, "SECOND='2'\n");

    expect(fileperms($this->target.'.backup') & 0777)->toBe(0600);
})->skipOnWindows();

it('skips the backup entirely when disabled', function () {
    file_put_contents($this->target, "FIRST='1'\n");

    writer(backup: false)->write($this->target, "SECOND='2'\n");

    expect(file_exists($this->target.'.backup'))->toBeFalse();
});

it('short circuits when the content is byte-identical', function () {
    $content = "KEY='value'\n";
    file_put_contents($this->target, $content);
    $mtime = filemtime($this->target);

    $outcome = writer()->write($this->target, $content);

    expect($outcome->written)->toBeFalse();
    // No backup on a no-op, or every timer tick would churn a .backup file.
    expect(file_exists($this->target.'.backup'))->toBeFalse();

    clearstatcache();
    expect(filemtime($this->target))->toBe($mtime);
});

it('detects a difference of a single byte', function () {
    file_put_contents($this->target, "KEY='value'\n");

    expect(writer()->write($this->target, "KEY='valuf'\n")->written)->toBeTrue();
});

it('creates the file when none exists', function () {
    expect(file_exists($this->target))->toBeFalse();

    $outcome = writer()->write($this->target, "KEY='value'\n");

    expect($outcome->written)->toBeTrue();
    expect($outcome->backupPath)->toBeNull();
});

it('replaces the target by rename rather than truncating it in place', function () {
    // The shell-redirect bug: `doppler ... > .env` creates and truncates before the
    // command on the left even runs, so an unreachable source leaves a zero-byte file.
    //
    // A rename swaps in a different inode. An open-truncate-write keeps the same one, so
    // the inode is direct evidence of which strategy ran.
    file_put_contents($this->target, "ORIGINAL='keep'\n");
    clearstatcache();
    $inodeBefore = fileinode($this->target);

    writer()->write($this->target, "REPLACED='new'\n");

    clearstatcache();
    expect(file_get_contents($this->target))->toBe("REPLACED='new'\n");
    expect(fileinode($this->target))->not->toBe($inodeBefore);
});

it('refuses a second concurrent write rather than interleaving hooks', function () {
    $lockPath = $this->target.'.lock';
    $handle = fopen($lockPath, 'c');

    expect($handle)->not->toBeFalse();
    assert(is_resource($handle));

    flock($handle, LOCK_EX | LOCK_NB);

    try {
        expect(fn () => writer()->write($this->target, "KEY='value'\n"))
            ->toThrow(WriteFailed::class, 'Another sync is running');
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('releases the lock so a later write succeeds', function () {
    writer()->write($this->target, "FIRST='1'\n");
    writer()->write($this->target, "SECOND='2'\n");

    expect(file_get_contents($this->target))->toBe("SECOND='2'\n");
});

describe('ownership', function () {
    it('resolves the owner from the first existing candidate', function () {
        file_put_contents($this->target, 'x');

        $resolved = (new Ownership([$this->dir.'/missing', $this->target]))->resolve();

        expect($resolved)->not->toBeNull();
        assert($resolved !== null);

        expect($resolved['source'])->toBe($this->target);
        expect($resolved['uid'])->toBe(fileowner($this->target));
    });

    it('returns null when no candidate exists', function () {
        expect((new Ownership([$this->dir.'/a', $this->dir.'/b']))->resolve())->toBeNull();
    });

    it('reports whether the written file is readable by the current user', function () {
        // A 0600 file owned by root is invisible to php-fpm, and every config value
        // silently becomes empty.
        $outcome = writer()->write($this->target, "KEY='value'\n");

        expect($outcome->readable)->toBeTrue();
    });

    it('reports a chown it cannot perform, without raising or aborting', function () {
        file_put_contents($this->target, 'x');

        // Only root can change a file's owner. As anyone else this must report false and
        // leave the file alone, rather than raising or aborting a sync that is otherwise
        // correct: on Windows and in rootless containers this is a no-op, not an error.
        $applied = (new Ownership([]))->apply($this->target, 0, 0);

        expect($applied)->toBe(function_exists('posix_geteuid') && posix_geteuid() === 0);
        expect(file_exists($this->target))->toBeTrue();
    })->skipOnWindows();
});
