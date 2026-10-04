<?php

namespace App\Console\Commands;

use App\Models\Deployment;
use App\Models\Server;
use App\Services\ClaudeAgentService;
use App\Services\RollbackService;
use App\Services\SshService;
use Illuminate\Console\Command;

class DeployRollbackCommand extends Command
{
    protected $signature   = 'deploy:rollback {--server= : Server name or ID}';
    protected $description = 'Roll back to the last successful deployment on a server';

    public function handle(): int
    {
        $serverQuery = $this->option('server');

        $server = is_numeric($serverQuery)
            ? Server::find($serverQuery)
            : Server::where('name', $serverQuery)->first();

        if (!$server) {
            $this->error("Server '{$serverQuery}' not found.");
            return self::FAILURE;
        }

        $deployment = $server->deployments()->where('status', 'success')->latest()->first();

        if (!$deployment) {
            $this->error('No successful deployment found to roll back to.');
            return self::FAILURE;
        }

        $this->info("Rolling back server '{$server->name}' to deployment #{$deployment->id}...");

        $ssh      = new SshService($server);
        $ssh->connect();
        $claude   = app(ClaudeAgentService::class);
        $rollback = new RollbackService($ssh, $claude);
        $rollback->rollback($deployment);
        $ssh->disconnect();

        $this->info('Rollback complete.');
        return self::SUCCESS;
    }
}
