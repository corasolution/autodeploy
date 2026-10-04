<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\SshService;
use Illuminate\Console\Command;

class DeployTestConnectionCommand extends Command
{
    protected $signature = 'deploy:test-connection {--server= : Server name or ID}';

    protected $description = 'Test SSH connection to a server';

    public function handle(): int
    {
        $serverQuery = $this->option('server');

        $server = is_numeric($serverQuery)
            ? Server::find($serverQuery)
            : Server::where('name', $serverQuery)->first();

        if (! $server) {
            $this->error("Server '{$serverQuery}' not found.");

            return self::FAILURE;
        }

        $this->info("Testing SSH connection to {$server->host}:{$server->ssh_port}...");

        $ssh = new SshService($server);
        $ok = $ssh->testConnection();

        if ($ok) {
            $this->info('SSH connection successful.');

            return self::SUCCESS;
        }

        $this->error('SSH connection failed.');

        return self::FAILURE;
    }
}
