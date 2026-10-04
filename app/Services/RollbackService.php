<?php

namespace App\Services;

use App\Models\DeployLog;
use App\Models\Deployment;

class RollbackService
{
    public function __construct(
        private SshService $ssh,
        private ClaudeAgentService $claude
    ) {}

    /**
     * Allow DeployService to hand in its already-connected SshService so
     * rollback doesn't try to reuse a disconnected DI-resolved instance.
     */
    public function setSsh(SshService $ssh): void
    {
        $this->ssh = $ssh;
    }

    public function rollback(Deployment $deployment, string $errorLog = ''): void
    {
        $server = $deployment->server;
        $site = $deployment->site;
        $deployPath = $site->deploy_path;
        DeployLog::record($deployment->id, 6, 28, 'info', null,
            'Starting rollback for deployment #'.$deployment->id);

        if ($errorLog) {
            try {
                $diagnosis = $this->claude->diagnoseError($errorLog, '');
                DeployLog::record($deployment->id, 6, 28, 'info', null,
                    'AI Diagnosis: '.json_encode($diagnosis), null, $diagnosis);
            } catch (\Throwable $e) {
                DeployLog::record($deployment->id, 6, 28, 'warning', null,
                    'AI diagnosis unavailable: '.$e->getMessage());
            }
        }

        // Atomic sites roll back by pointing `current` at the previous release
        // — instant, and it restores the exact code that was running before.
        //
        // The old path rsync'd a local snapshot directory into a REMOTE path,
        // which could never work, and createSnapshot() was never called anyway,
        // so $snapshotDir never existed (F4).
        if ($site->isAtomic() && $deployment->previous_release) {
            $atomic = new AtomicReleaseService($this->ssh);

            $exists = $this->ssh->exec(
                '[ -d '.escapeshellarg($deployment->previous_release).' ] && echo yes || echo no'
            );

            if (trim($exists['output'] ?? '') === 'yes') {
                $atomic->switchTo($site, $deployment->previous_release);
                $reload = $atomic->reload($site);

                // R2: workers are still running the failed release's code until
                // they are told to restart, and `current` now points back at the
                // previous one.
                $php = escapeshellarg($site->php_binary ?: 'php');
                $restart = $this->ssh->exec(
                    'cd '.escapeshellarg($site->currentPath())." && {$php} artisan queue:restart 2>&1"
                );
                DeployLog::record($deployment->id, 6, 22,
                    ($restart['exit_code'] ?? 1) === 0 ? 'success' : 'warning',
                    'php artisan queue:restart', $restart['output'] ?? '', $restart['exit_code'] ?? null);

                DeployLog::record($deployment->id, 6, 29, 'success', null,
                    'Rolled back: current → '.basename($deployment->previous_release)
                    .($reload['skipped'] ? ' (no reload_command set)' : ' and PHP-FPM reloaded'));
            } else {
                DeployLog::record($deployment->id, 6, 29, 'error', null,
                    'Cannot roll back — previous release '.$deployment->previous_release
                    .' no longer exists on the server (pruned?). The failed release is still live.');
            }
        } elseif ($site->isAtomic()) {
            DeployLog::record($deployment->id, 6, 29, 'warning', null,
                'No previous release recorded (first deploy) — nothing to roll back to.');
        } else {
            DeployLog::record($deployment->id, 6, 29, 'warning', null,
                'Site is in in_place mode, so there is no previous release to restore. '
                .'Convert it with `php artisan deploy:convert-atomic` to get real rollbacks.');
        }

        // Migrations are NOT rolled back automatically (Phase 2). Running
        // migrate:rollback against a schema the restored code may not expect is
        // how one bad deploy becomes two — and `down()` methods are frequently
        // untested. Surface it and let a human decide.
        $migrationsRan = $deployment->logs()
            ->where('phase', 4)
            ->where('step', 16)
            ->where('status', 'success')
            ->exists();

        if ($migrationsRan) {
            DeployLog::record($deployment->id, 6, 30, 'warning', null,
                'This deploy ran migrations. The code was rolled back but the DATABASE SCHEMA '
                .'was NOT — the restored release is running against the new schema. If that '
                .'breaks it, roll the migration back manually. Additive (expand/contract) '
                .'migrations avoid this entirely.');
        }

        $php = escapeshellarg($site->php_binary ?: 'php');
        $path = escapeshellarg($deployment->previous_release ?: $deployPath);

        // Bring app back up
        $result = $this->ssh->exec("cd {$path} && {$php} artisan up 2>&1");
        DeployLog::record($deployment->id, 6, 31, $result['exit_code'] === 0 ? 'success' : 'error',
            'php artisan up', $result['output'], $result['exit_code']);

        $deployment->markFinished('rolled_back');
    }

    /**
     * Rolling back no longer rolls migrations back automatically.
     *
     * A symlink swap restores the previous CODE instantly, but `migrate:rollback`
     * on a schema the old code may not match is how one bad deploy becomes two.
     * The UI warns when the rolled-back deploy ran migrations; prefer additive
     * (expand/contract) migrations so the old release keeps working against the
     * new schema.
     */
    public function rollbackMigrations(Deployment $deployment): void
    {
        $site = $deployment->site;
        $php = escapeshellarg($site->php_binary ?: 'php');
        $path = escapeshellarg($deployment->previous_release ?: $site->deploy_path);

        $result = $this->ssh->exec("cd {$path} && {$php} artisan migrate:rollback --force 2>&1");

        DeployLog::record($deployment->id, 6, 30, $result['exit_code'] === 0 ? 'success' : 'error',
            'php artisan migrate:rollback --force', $result['output'], $result['exit_code']);
    }
}
