<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Commands;

use AlexHackney\Doppler\Credentials\Credential;
use AlexHackney\Doppler\Credentials\TokenResolver;
use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\Exceptions\DopplerException;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\SyncOptions;
use Illuminate\Console\Command;

/**
 * Writes the encrypted fallback snapshot without rendering the environment file.
 *
 * The snapshot covers exactly the gap soft-fail cannot: there is no previous file to keep.
 * A brand new box provisioned during an outage, or an immutable image with no mounted
 * checkout.
 */
final class SnapshotCommand extends Command
{
    protected $signature = 'env:snapshot
        {--token= : Doppler token}
        {--token-file= : Path to a token file}
        {--project= : Doppler project}
        {--config= : Doppler config}
        {--profile= : Named profile from config/doppler.php}
        {--driver= : api or cli}
        {--fallback= : Where to write the snapshot}';

    protected $description = 'Write an encrypted local snapshot of the current secrets';

    public function handle(DopplerManager $doppler): int
    {
        $options = new SyncOptions(
            token: $this->stringOption('token'),
            tokenFile: $this->stringOption('token-file'),
            project: $this->stringOption('project'),
            config: $this->stringOption('config'),
            profile: $this->stringOption('profile'),
            driver: $this->stringOption('driver'),
            fallbackPath: $this->stringOption('fallback'),
        );

        $config = $doppler->resolveConfig($options);

        $store = $doppler->snapshotStore($config, $options);

        try {
            $secrets = $doppler->secrets($options);

            // Resolving the credential a second time is deliberate: the snapshot key
            // derives from the token by default, so the store needs the same credential
            // the fetch used.
            $store->write($secrets, $this->credential($config, $options));
        } catch (DopplerException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return $e->exitCode()->value;
        }

        $this->components->info(sprintf(
            'Wrote an encrypted snapshot of %d secrets to %s.',
            count($secrets),
            $store->path(),
        ));

        return ExitCode::Success->value;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function credential(array $config, SyncOptions $options): Credential
    {
        /** @var list<string> $files */
        $files = data_get($config, 'token.files', []);

        $resolver = new TokenResolver(
            files: $files,
            envVar: (string) data_get($config, 'token.env_var', 'DOPPLER_TOKEN'),
        );

        return $resolver->resolve(
            explicitToken: $options->token,
            explicitFile: $options->tokenFile,
            project: $options->project,
            config: $options->config,
        );
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
