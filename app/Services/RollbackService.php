<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\DeployLog;
use RuntimeException;

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
        $server      = $deployment->server;
        $site        = $deployment->site;
        $deployPath  = $site->deploy_path;
        $snapshotDir = config('autopilot.deploy.snapshots_path')
            . DIRECTORY_SEPARATOR . $deployment->id;

        DeployLog::record($deployment->id, 6, 28, 'info', null,
            'Starting rollback for deployment #' . $deployment->id);

        if ($errorLog) {
            try {
                $diagnosis = $this->claude->diagnoseError($errorLog, '');
                DeployLog::record($deployment->id, 6, 28, 'info', null,
                    'AI Diagnosis: ' . json_encode($diagnosis), null, $diagnosis);
            } catch (\Throwable $e) {
                DeployLog::record($deployment->id, 6, 28, 'warning', null,
                    'AI diagnosis unavailable: ' . $e->getMessage());
            }
        }

        // Restore snapshot
        if (is_dir($snapshotDir)) {
            $result = $this->ssh->exec(
                'rsync -az --delete ' . escapeshellarg($snapshotDir . '/') . ' '
                . escapeshellarg($deployPath . '/')
            );
            DeployLog::record($deployment->id, 6, 29, $result['exit_code'] === 0 ? 'success' : 'error',
                'rsync snapshot restore', $result['output'], $result['exit_code']);
        }

        // Rollback migrations if they ran
        $migrationsRan = $deployment->logs()
            ->where('phase', 4)
            ->where('step', 16)
            ->where('status', 'success')
            ->exists();

        $php  = escapeshellarg($site->php_binary ?: 'php');
        $path = escapeshellarg($deployPath);

        if ($migrationsRan) {
            $result = $this->ssh->exec("cd {$path} && {$php} artisan migrate:rollback --force 2>&1");
            DeployLog::record($deployment->id, 6, 30, $result['exit_code'] === 0 ? 'success' : 'warning',
                'php artisan migrate:rollback --force', $result['output'], $result['exit_code']);
        }

        // Bring app back up
        $result = $this->ssh->exec("cd {$path} && {$php} artisan up 2>&1");
        DeployLog::record($deployment->id, 6, 31, $result['exit_code'] === 0 ? 'success' : 'error',
            'php artisan up', $result['output'], $result['exit_code']);

        $deployment->markFinished('rolled_back');
    }

    public function createSnapshot(Deployment $deployment): void
    {
        $deployPath  = $deployment->site->deploy_path;
        $snapshotDir = config('autopilot.deploy.snapshots_path')
            . DIRECTORY_SEPARATOR . $deployment->id;

        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0755, true);
        }

        $result = $this->ssh->exec(
            'rsync -az --exclude=.git --exclude=node_modules ' .
            escapeshellarg($deployPath . '/') . ' ' .
            escapeshellarg($snapshotDir . '/')
        );

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Snapshot creation failed: ' . $result['output']);
        }
    }
}
