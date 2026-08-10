<?php

declare(strict_types=1);

namespace AlexHackney\Doppler\Commands;

use AlexHackney\Doppler\DopplerManager;
use AlexHackney\Doppler\Exceptions\DopplerException;
use AlexHackney\Doppler\Exceptions\HookFailed;
use AlexHackney\Doppler\Exceptions\ValidationFailed;
use AlexHackney\Doppler\Support\ExitCode;
use AlexHackney\Doppler\Support\SyncResult;
use AlexHackney\Doppler\SyncOptions;
use Illuminate\Console\Command;

/**
 * Renders the environment file from Doppler.
 *
 * Zero arguments is the common case, and the deploy script line is just:
 *
 *     php artisan env:sync
 *
 * with no `|| true` after it. That matters: `|| true` swallows every failure mode, not
 * only the one somebody was trying to tolerate. Soft-fail exists so the tolerable case can
 * be expressed once, in config, without hiding the rest.
 */
final class SyncCommand extends Command
{
    protected $signature = 'env:sync
        {--token= : Doppler token. Discouraged: visible in ps and in shell history}
        {--token-file= : Path to a token file. When given, no other location is searched}
        {--project= : Doppler project. Required for dp.pt. and dp.sa. tokens}
        {--config= : Doppler config. Required for dp.pt. and dp.sa. tokens}
        {--profile= : Named profile from config/doppler.php}
        {--path= : Target file. Defaults to the configured target path}
        {--target= : Grammar: laravel, systemd, docker or shell}
        {--driver= : api, cli or snapshot}
        {--only= : Comma-separated allow-list of keys}
        {--except= : Comma-separated deny-list of keys}
        {--dry-run : Report the key-level diff and write nothing}
        {--stdout : Print the rendered content and write nothing. Implies --no-hooks}
        {--force : Write despite validation problems}
        {--soft-fail : An unreachable source exits 0 if the existing file is valid}
        {--no-soft-fail : Override a config-level soft_fail of true}
        {--no-hooks : Skip every post-sync hook}
        {--fallback= : Snapshot path to use when the source is unreachable}
        {--fallback-only : Render from the snapshot without contacting the source}
        {--write-fallback : Refresh the snapshot after a successful render}';

    protected $description = 'Render the environment file from Doppler, atomically and verified';

    public function handle(DopplerManager $doppler): int
    {
        $options = $this->buildOptions();

        $this->warnAboutInlineToken();

        try {
            $result = $doppler->sync($options);
        } catch (ValidationFailed $e) {
            return $this->reportValidationFailure($e);
        } catch (HookFailed $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return ExitCode::HookFailed->value;
        } catch (DopplerException $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return $e->exitCode()->value;
        }

        if ($this->option('stdout')) {
            $this->line($result->renderedContent ?? '');

            return ExitCode::Success->value;
        }

        return $this->report($result);
    }

    private function buildOptions(): SyncOptions
    {
        $stdout = (bool) $this->option('stdout');

        return new SyncOptions(
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
            // --stdout is a dry run that prints, so it can never write or fire hooks.
            dryRun: (bool) $this->option('dry-run') || $stdout,
            force: (bool) $this->option('force'),
            softFail: $this->softFailPreference(),
            runHooks: ! $this->option('no-hooks') && ! $stdout,
            fallbackPath: $this->stringOption('fallback'),
            fallbackOnly: (bool) $this->option('fallback-only'),
            writeFallback: (bool) $this->option('write-fallback'),
        );
    }

    /**
     * Null means "whatever config says". An explicit flag in either direction wins.
     */
    private function softFailPreference(): ?bool
    {
        if ($this->option('no-soft-fail')) {
            return false;
        }

        if ($this->option('soft-fail')) {
            return true;
        }

        return null;
    }

    private function warnAboutInlineToken(): void
    {
        if ($this->stringOption('token') !== null) {
            $this->components->warn(
                'A token passed with --token is visible in ps to every user on this box, and lands in shell history. '.
                'Prefer --token-file or a provisioned token file.',
            );
        }
    }

    private function report(SyncResult $result): int
    {
        if ($result->softFailed) {
            return $this->reportSoftFail($result);
        }

        if ($result->hasProblems()) {
            $this->newLine();
            $this->components->warn(sprintf('%d validation problem(s) were forced past:', count($result->problems)));

            foreach ($result->problems as $problem) {
                $this->line('  '.$problem->describe());
            }
        }

        if ($result->unchanged && ! $result->written) {
            if ($this->option('dry-run')) {
                $this->components->info('No drift. The rendered content matches the current file.');

                return ExitCode::Success->value;
            }

            $this->components->info(sprintf(
                '%s is already current (%d secrets). Nothing written, no hooks run.',
                $result->path,
                $result->secretCount,
            ));

            return ExitCode::Success->value;
        }

        if ($this->option('dry-run')) {
            return $this->reportDryRun($result);
        }

        $this->components->info(sprintf('Wrote %s (%d secrets, %s target).', $result->path, $result->secretCount, $result->target));

        $this->reportDiff($result);
        $this->reportOverlayAndPreserved($result);

        foreach ($result->hooks as $hook) {
            $this->components->twoColumnDetail($hook->hook, $hook->successful ? 'ok' : 'failed');
        }

        return $result->exitCode->value;
    }

    private function reportDryRun(SyncResult $result): int
    {
        $this->components->info(sprintf('Dry run against %s (%d secrets). Nothing was written.', $result->path, $result->secretCount));

        $this->reportDiff($result);
        $this->reportOverlayAndPreserved($result);

        // Exit 10 makes drift detectable from a script without parsing output.
        return $result->diff?->hasDrift() ? ExitCode::DriftDetected->value : ExitCode::Success->value;
    }

    private function reportSoftFail(SyncResult $result): int
    {
        $this->newLine();
        $this->error(str_repeat('=', 78));
        $this->error('  DOPPLER UNREACHABLE');
        $this->error('  THE EXISTING ENVIRONMENT FILE WAS KEPT');
        $this->error(str_repeat('=', 78));
        $this->newLine();
        $this->line('  Reason:  '.($result->softFailReason ?? 'unknown'));
        $this->line('  Kept:    '.$result->path);
        $this->line('  Summary: '.$result->summary());
        $this->newLine();
        $this->line('  This deploy is running on secrets that were not re-fetched. An error-level');
        $this->line('  log record was emitted. Schedule env:diff to catch a box that stays stale.');
        $this->newLine();

        return ExitCode::Success->value;
    }

    private function reportDiff(SyncResult $result): void
    {
        $diff = $result->diff;

        if ($diff === null || ! $diff->hasDrift()) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('  %d key(s) differ from the current file:', $diff->driftCount()));

        foreach ($diff->lines() as $line) {
            $this->line('    '.$line);
        }

        $this->newLine();
    }

    private function reportOverlayAndPreserved(SyncResult $result): void
    {
        if ($result->overlaidKeys !== []) {
            $this->components->twoColumnDetail(
                'Overlaid from the host-local file',
                implode(', ', $result->overlaidKeys),
            );
        }

        if ($result->preservedKeys !== []) {
            $this->components->twoColumnDetail(
                'Carried forward from the existing file',
                implode(', ', $result->preservedKeys),
            );
        }
    }

    private function reportValidationFailure(ValidationFailed $e): int
    {
        $this->newLine();
        $this->error($e->getMessage());
        $this->newLine();

        foreach ($e->problems as $problem) {
            $this->line('  - '.$problem->describe());
        }

        $this->newLine();
        $this->line('  Nothing was written. The existing file is untouched.');
        $this->line('  Use --force to write anyway, if you have a reason to.');
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
