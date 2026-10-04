<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\ClaudeAgentService;
use App\Services\SshService;
use Illuminate\Console\Command;

class DeployAuditCommand extends Command
{
    protected $signature = 'deploy:audit {--server= : Server name or ID}';

    protected $description = 'Run AI audit on current .env vs server environment';

    public function handle(ClaudeAgentService $claude): int
    {
        $serverQuery = $this->option('server');

        $server = is_numeric($serverQuery)
            ? Server::find($serverQuery)
            : Server::where('name', $serverQuery)->first();

        if (! $server) {
            $this->error("Server '{$serverQuery}' not found.");

            return self::FAILURE;
        }

        $this->info("Running AI config audit for server '{$server->name}'...");

        $ssh = new SshService($server);
        $ssh->connect();

        $remoteEnv = $ssh->exec('cat '.escapeshellarg($server->deploy_path.'/.env').' 2>/dev/null');
        $localEnv = file_get_contents(base_path('.env'));
        $ssh->disconnect();

        $envDiff = "=== LOCAL .env ===\n{$localEnv}\n\n=== REMOTE .env ===\n".$remoteEnv['output'];

        $migrations = array_map('basename', glob(database_path('migrations/*.php')));

        try {
            $result = $claude->auditConfigSafe($envDiff, $migrations);

            $this->info('Risk Level: '.strtoupper($result['risk_level'] ?? 'unknown'));

            if (! empty($result['warnings'])) {
                $this->warn('Warnings:');
                foreach ($result['warnings'] as $w) {
                    $this->line("  - {$w}");
                }
            }

            if (! empty($result['suggestions'])) {
                $this->info('Suggestions:');
                foreach ($result['suggestions'] as $s) {
                    $this->line("  - {$s}");
                }
            }
        } catch (\Throwable $e) {
            $this->error('AI audit failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
