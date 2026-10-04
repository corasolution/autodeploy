<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

class DeployServersCommand extends Command
{
    protected $signature   = 'deploy:servers';
    protected $description = 'List all registered server profiles';

    public function handle(): int
    {
        $servers = Server::all(['id', 'name', 'panel_type', 'host', 'ssh_user', 'active']);

        if ($servers->isEmpty()) {
            $this->warn('No servers registered.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Panel', 'Host', 'SSH User', 'Active'],
            $servers->map(fn($s) => [
                $s->id, $s->name, $s->panel_type, $s->host, $s->ssh_user, $s->active ? 'yes' : 'no',
            ])
        );

        return self::SUCCESS;
    }
}
