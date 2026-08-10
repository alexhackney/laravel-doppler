<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Credentials;

use SensitiveParameter;

/**
 * A resolved Doppler token, together with where it came from.
 *
 * The raw value is deliberately awkward to reach: __toString, __debugInfo and var_dump
 * output are all redacted, so a token cannot leak through an exception trace, a dd(), or
 * a log line that interpolates the object. Reading the real value takes an explicit
 * reveal() call, which is greppable.
 */
final class Credential
{
    /**
     * @param  string  $token  The raw token. Never print this; call redacted() instead.
     * @param  TokenType  $type  Classified by prefix.
     * @param  string  $source  Human-readable origin, e.g. "/etc/doppler/.token" or "--token".
     * @param  string|null  $project  Doppler project, when explicitly scoped.
     * @param  string|null  $config  Doppler config, when explicitly scoped.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $token,
        public readonly TokenType $type,
        public readonly string $source,
        public readonly ?string $project = null,
        public readonly ?string $config = null,
    ) {}

    /**
     * Build from a raw token string, classifying the type by prefix.
     */
    public static function make(
        #[SensitiveParameter]
        string $token,
        string $source,
        ?string $project = null,
        ?string $config = null,
    ): self {
        return new self(
            token: $token,
            type: TokenType::fromToken($token),
            source: $source,
            project: $project,
            config: $config,
        );
    }

    /**
     * The raw token value.
     *
     * Only the HTTP layer and the CLI driver should call this.
     */
    public function reveal(): string
    {
        return $this->token;
    }

    /**
     * A safe rendering: the prefix, then the last four characters.
     *
     * Short tokens are fully masked rather than partially revealed, because four
     * characters of an eight-character secret is a meaningful fraction of it.
     */
    public function redacted(): string
    {
        $prefixes = ['dp.st.', 'dp.pt.', 'dp.sa.'];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($this->token, $prefix)) {
                $body = substr($this->token, strlen($prefix));

                return $prefix.$this->maskBody($body);
            }
        }

        return $this->maskBody($this->token);
    }

    /**
     * Return a copy scoped to a project and config.
     */
    public function withScope(?string $project, ?string $config): self
    {
        return new self(
            token: $this->token,
            type: $this->type,
            source: $this->source,
            project: $project ?? $this->project,
            config: $config ?? $this->config,
        );
    }

    /**
     * Whether this credential still needs a project and config before it can be used.
     */
    public function needsScope(): bool
    {
        return $this->type->requiresScope()
            && ($this->project === null || $this->config === null);
    }

    private function maskBody(string $body): string
    {
        if (strlen($body) <= 8) {
            return str_repeat('*', max(strlen($body), 4));
        }

        return '****'.substr($body, -4);
    }

    public function __toString(): string
    {
        return $this->redacted();
    }

    /**
     * @return array<string, string|null>
     */
    public function __debugInfo(): array
    {
        return [
            'token' => $this->redacted(),
            'type' => $this->type->value,
            'source' => $this->source,
            'project' => $this->project,
            'config' => $this->config,
        ];
    }
}
