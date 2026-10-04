<?php

namespace App\Console\Commands;

use App\Jobs\RunDeploymentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Services\ClaudeAgentService;
use App\Services\DeployService;
use App\Services\RollbackService;
use Illuminate\Console\Command;

class DeployRunCommand extends Command
{
    protected $signature = 'deploy:run
        {--server= : Server name or ID}
        {--site= : Site name or ID — required when the server hosts more than one site}
        {--branch=main : Git branch to deploy}
        {--dry-run : Show what would happen without executing}';

    protected $description = 'Deploy to a server';

    public function handle(): int
    {
        $serverQuery = $this->option('server');
        $siteQuery   = $this->option('site');
        $branch      = $this->option('branch');
        $dryRun      = $this->option('dry-run');

        // Site resolves first — DeployService::run() reads its target path,
        // deploy path, and repo/source settings entirely off the Site model
        // (see app/Services/DeployService.php:62-75), so a Deployment with no
        // site_id has nothing to deploy and fails deep inside the queue job
        // instead of here, where the mistake is still cheap to catch.
        $site = null;
        if ($siteQuery !== null) {
            $site = is_numeric($siteQuery)
                ? Site::find($siteQuery)
                : Site::where('name', $siteQuery)->first();

            if (!$site) {
                $this->error("Site '{$siteQuery}' not found.");
                return self::FAILURE;
            }
        }

        $server = null;
        if ($serverQuery !== null) {
            $server = is_numeric($serverQuery)
                ? Server::find($serverQuery)
                : Server::where('name', $serverQuery)->first();

            if (!$server) {
                $this->error("Server '{$serverQuery}' not found.");
                return self::FAILURE;
            }
        }

        if ($site && $server && $site->server_id !== $server->id) {
            $this->error("Site '{$site->name}' does not belong to server '{$server->name}' (it belongs to server #{$site->server_id}).");
            return self::FAILURE;
        }

        // --site alone is enough to know both the site and its server.
        if ($site && !$server) {
            $server = $site->server;
        }

        if (!$server) {
            $this->error('Specify --server (and --site, if that server hosts more than one site).');
            return self::FAILURE;
        }

        // --server alone is only safe to resolve when it hosts exactly one
        // site — with more than one, silently picking "the first" is exactly
        // the ambiguity this flag exists to remove.
        if (!$site) {
            $serverSites = $server->sites()->get();

            if ($serverSites->count() === 1) {
                $site = $serverSites->first();
            } elseif ($serverSites->isEmpty()) {
                $this->error("Server '{$server->name}' has no sites configured.");
                return self::FAILURE;
            } else {
                $this->error("Server '{$server->name}' hosts {$serverSites->count()} sites — specify --site to choose which one.");
                $this->table(['ID', 'Name', 'Deploy Path'], $serverSites->map(fn ($s) => [$s->id, $s->name, $s->deploy_path])->all());
                return self::FAILURE;
            }
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would deploy branch '{$branch}' to site '{$site->name}' on server '{$server->name}' ({$server->host})");
            $this->info("Deploy path: {$site->deploy_path}");
            $this->info("Source: " . ($site->source_path ?: $site->repo_url ?: '(none configured)'));
            $this->info("Panel type: {$server->panel_type}");
            return self::SUCCESS;
        }

        $deployment = Deployment::create([
            'server_id'    => $server->id,
            'site_id'      => $site->id,
            'branch'       => $branch,
            'triggered_by' => 'artisan',
            'status'       => 'pending',
        ]);

        $this->info("Deployment #{$deployment->id} queued for site '{$site->name}' on server '{$server->name}'.");
        RunDeploymentJob::dispatch($deployment);

        return self::SUCCESS;
    }
}
