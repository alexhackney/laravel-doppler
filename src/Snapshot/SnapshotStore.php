<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Snapshot;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Exceptions\SourceUnavailable;
use AlexHackney\Doppler\Exceptions\WriteFailed;
use SensitiveParameter;

/**
 * An encrypted local copy of the last good download.
 *
 * This covers exactly the gap soft-fail cannot: there is no previous file to keep. A brand
 * new box provisioned during an outage, or an immutable image with no mounted checkout.
 *
 * Encrypted at rest with AES-256-GCM. The key derives from the token by default, matching
 * the Doppler CLI's own model and needing no extra secret provisioned. The tradeoff is
 * that rotating the token invalidates every snapshot on the fleet at once, so an explicit
 * passphrase is supported for anyone who would rather provision one thing more than lose
 * their fallback during a rotation.
 *
 * Every read reports the snapshot's age. Falling back to a six week old snapshot without
 * saying so is worse than failing.
 */
final class SnapshotStore
{
    private const MAGIC = "DPSNAP\x01";

    private const CIPHER = 'aes-256-gcm';

    private const PBKDF2_ITERATIONS = 120000;

    public function __construct(
        private readonly string $path,
        #[SensitiveParameter]
        private readonly ?string $passphrase = null,
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path) && is_readable($this->path);
    }

    /**
     * When the snapshot was last written, or null if there is none.
     */
    public function writtenAt(): ?int
    {
        if (! $this->exists()) {
            return null;
        }

        $mtime = @filemtime($this->path);

        return $mtime === false ? null : $mtime;
    }

    /**
     * Age in whole days, or null if there is no snapshot.
     */
    public function ageInDays(?int $now = null): ?int
    {
        $writtenAt = $this->writtenAt();

        if ($writtenAt === null) {
            return null;
        }

        return (int) floor((($now ?? time()) - $writtenAt) / 86400);
    }

    /**
     * Encrypt and write a snapshot atomically.
     *
     * @param  array<string, string>  $secrets
     *
     * @throws WriteFailed
     */
    public function write(array $secrets, Credential $credential): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw WriteFailed::directoryNotWritable($this->path);
        }

        if (! is_writable($directory)) {
            throw WriteFailed::directoryNotWritable($this->path);
        }

        $plaintext = json_encode($secrets, JSON_THROW_ON_ERROR);

        $salt = random_bytes(16);
        $iv = random_bytes(12);
        $key = $this->deriveKey($credential, $salt);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new WriteFailed('Failed encrypting the snapshot.', $this->path);
        }

        $payload = self::MAGIC.$salt.$iv.$tag.$ciphertext;

        // Same atomic discipline as the main writer: temp file beside the target, then
        // rename, so a crash mid-write cannot leave a truncated snapshot that would then
        // fail to decrypt during the outage it exists to cover.
        $temp = $this->path.'.'.bin2hex(random_bytes(6)).'.tmp';

        // Create empty, chmod, THEN write. file_put_contents would create the file at the
        // umask default — typically 0644 — and the payload would land before the chmod,
        // leaving a real window in which every secret on the box is world readable. It is
        // encrypted, but SECURITY.md lists a snapshot written with permissions other than
        // 0600 as a reportable vulnerability, and AtomicWriter already gets this right.
        $handle = @fopen($temp, 'x');

        if ($handle === false) {
            throw WriteFailed::tempWriteFailed($this->path);
        }

        @chmod($temp, 0600);

        $bytes = @fwrite($handle, $payload);

        if ($bytes === false || $bytes !== strlen($payload)) {
            @fclose($handle);
            @unlink($temp);

            throw WriteFailed::tempWriteFailed($this->path);
        }

        @fflush($handle);

        if (function_exists('fsync')) {
            @fsync($handle);
        }

        @fclose($handle);

        if (! @rename($temp, $this->path)) {
            @unlink($temp);

            throw WriteFailed::renameFailed($this->path);
        }
    }

    /**
     * Read and decrypt the snapshot.
     *
     * @return array<string, string>
     *
     * @throws SourceUnavailable
     */
    public function read(Credential $credential): array
    {
        if (! $this->exists()) {
            throw SourceUnavailable::network('snapshot', sprintf('no snapshot exists at %s', $this->path));
        }

        $payload = @file_get_contents($this->path);

        if ($payload === false) {
            throw SourceUnavailable::network('snapshot', sprintf('%s could not be read', $this->path));
        }

        $magicLength = strlen(self::MAGIC);

        if (strlen($payload) < $magicLength + 44 || ! str_starts_with($payload, self::MAGIC)) {
            throw SourceUnavailable::network(
                'snapshot',
                sprintf('%s is not a snapshot written by this package, or it is truncated', $this->path),
            );
        }

        $salt = substr($payload, $magicLength, 16);
        $iv = substr($payload, $magicLength + 16, 12);
        $tag = substr($payload, $magicLength + 28, 16);
        $ciphertext = substr($payload, $magicLength + 44);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->deriveKey($credential, $salt),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($plaintext === false) {
            throw SourceUnavailable::network(
                'snapshot',
                sprintf(
                    '%s could not be decrypted. The token may have been rotated since it was written, '.
                    'which invalidates a token-derived key. Set a fallback passphrase to survive rotations.',
                    $this->path,
                ),
            );
        }

        $decoded = json_decode($plaintext, true);

        if (! is_array($decoded)) {
            throw SourceUnavailable::network('snapshot', 'the decrypted snapshot was not a JSON object');
        }

        $secrets = [];

        foreach ($decoded as $key => $value) {
            // Refuse rather than skip. Silently dropping a key would hand back a partial
            // secret set during the outage this file exists to cover, and a partial set
            // renders and writes cleanly — which is the exact silent-corruption class the
            // rest of this package refuses by construction.
            if (! is_string($key) || ! is_string($value)) {
                throw SourceUnavailable::network(
                    'snapshot',
                    sprintf(
                        '%s decrypted to something that is not a flat map of strings, so it '.
                        'cannot be trusted as a complete secret set.',
                        $this->path,
                    ),
                );
            }

            $secrets[$key] = $value;
        }

        return $secrets;
    }

    /**
     * Derive the encryption key.
     *
     * PBKDF2 with a per-snapshot random salt, so two boxes holding the same secrets do not
     * produce byte-identical files, and so a stolen snapshot cannot be attacked offline
     * as cheaply as a raw hash would allow.
     */
    private function deriveKey(Credential $credential, string $salt): string
    {
        $material = $this->passphrase ?? $credential->reveal();

        return hash_pbkdf2('sha256', $material, $salt, self::PBKDF2_ITERATIONS, 32, true);
    }
}
