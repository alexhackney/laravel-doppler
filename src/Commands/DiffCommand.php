<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Commands;

use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\Exceptions\DopplerException;
use AlexHackney\Doppler\Exceptions\ValidationFailed;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\SyncOptions;
use Illuminate\Console\Command;

/**
 * The read-only half of env:sync.
 *
 * Exits 10 on drift, which makes it safe to schedule: it is how you find out that somebody
 * hand-edited a box, or that a box has been quietly running on a stale file since the last
 * soft-fail. A soft-fail is invisible by construction (the deploy is green and the app is
 * up), so this is the backstop that catches it a day later rather than a month later.
 *
 * Never prints a value.
 */
final class DiffCommand extends Command
{
    protected $signature = 'env:diff
        {--token= : Doppler token}
        {--token-file= : Path to a token file}
        {--project= : Doppler project}
        {--config= : Doppler config}
        {--profile= : Named profile from config/doppler.php}
        {--path= : File to compare against}
        {--target= : Grammar: laravel, systemd, docker or shell}
        {--driver= : api, cli or snapshot}
        {--only= : Comma-separated allow-list of keys}
        {--except= : Comma-separated deny-list of keys}';

    protected $description = 'Report key-level drift between Doppler and the current environment file';

    public function handle(DopplerManager $doppler): int
    {
        $options = new SyncOptions(
            token: $this->stringOption('token'),
            tokenFile: $this->stringOption('token-file'),
            project: $this->stringOption('project'),
            config: $this->stringOption('config'),
            profile: $this->stringOption('profile'),
            path: $this->stringOption('path'),
            target: $this->stringOption('target'),
            driver: $this->stringOption('driver'),
            only: $this->listOption('only'),
            except: $this->listOption('except'),
            dryRun: true,
            runHooks: false,
        );

        try {
            $result = $doppler->dryRun($options);
        } catch (ValidationFailed $e) {
            return $this->reportValidationFailure($e);
        } catch (DopplerException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return $e->exitCode()->value;
        }

        $diff = $result->diff;

        if ($diff === null || ! $diff->hasDrift()) {
            $this->components->info(sprintf(
                '%s matches Doppler (%d secrets). No drift.',
                $result->path,
                $result->secretCount,
            ));

            return ExitCode::Success->value;
        }

        $this->newLine();
        $this->components->warn(sprintf('%d key(s) drifted from Doppler:', $diff->driftCount()));
        $this->newLine();

        foreach ($diff->lines() as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->line('  Values are never shown. Run env:sync to reconcile.');
        $this->newLine();

        return ExitCode::DriftDetected->value;
    }

    /**
     * Doppler answered, and the answer would not pass validation.
     *
     * The problems are printed in full, exactly as env:sync prints them. A count alone is
     * useless from a scheduler: this is the command you run unattended, so the run that
     * tells you something is wrong is the only place the detail is available without
     * somebody reproducing it by hand through the facade.
     *
     * The header is worded for a read-only command rather than reusing the exception's own
     * message, which ends "Nothing was written" and reads on env:diff as though a write had
     * been attempted. No --force advice either, for the same reason: this command has no
     * such flag.
     */
    private function reportValidationFailure(ValidationFailed $e): int
    {
        $count = count($e->problems);

        $this->newLine();
        $this->error(sprintf(
            '%d validation problem%s would refuse the next env:sync.',
            $count,
            $count === 1 ? '' : 's',
        ));
        $this->newLine();

        foreach ($e->problems as $problem) {
            $this->line('  - '.$problem->describe());
        }

        $this->newLine();
        $this->line('  Drift was not compared: validation refuses before the comparison is computed.');
        $this->newLine();

        return ExitCode::ValidationFailed->value;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $value = $this->stringOption($name);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $s): bool => $s !== ''));
    }
}
