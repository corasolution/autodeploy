<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\SshService;
use Illuminate\Console\Command;

/**
 * UPGRADE-v2 Phase 2 — one-time converter from in_place to atomic layout.
 *
 * Before:  {deploy_path}/{app files}
 * After:   {deploy_path}/releases/initial/{app files}
 *          {deploy_path}/shared/{.env,storage,backups}
 *          {deploy_path}/current -> releases/initial
 *
 * This MOVES live files, so it refuses to guess: --dry-run prints the plan and
 * changes nothing, and the real run requires the site to be reachable and the
 * layout to not already exist.
 *
 * After converting you MUST repoint the panel's web root to
 * {deploy_path}/current/public or the site will 404.
 */
class DeployConvertAtomicCommand extends Command
{
    protected $signature = 'deploy:convert-atomic
                            {site : Site id or name}
                            {--dry-run : Print the plan without changing anything}';

    protected $description = 'Convert a site from in_place deploys to the atomic releases/ + shared/ layout';

    public function handle(): int
    {
        $identifier = $this->argument('site');
        $site = is_numeric($identifier)
            ? Site::with('server')->find((int) $identifier)
            : Site::with('server')->where('name', $identifier)->first();

        if (! $site) {
            $this->error("Site [{$identifier}] not found.");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $base = rtrim($site->deploy_path, '/');
        $shared = $site->sharedPath();
        $initial = $base.'/releases/initial';

        $this->info("Site:        {$site->name}");
        $this->info("Server:      {$site->server->name} ({$site->server->host})");
        $this->info("Deploy path: {$base}");
        $this->newLine();

        $ssh = new SshService($site->server);
        $ssh->connect();

        // Refuse to run twice — a second pass would move an already-converted
        // tree into releases/initial/releases and break the site.
        $already = trim($ssh->exec('[ -L '.escapeshellarg($base.'/current').' ] && echo yes || echo no')['output'] ?? '');
        if ($already === 'yes') {
            $this->warn('This site already has a `current` symlink — it looks converted already.');
            $ssh->disconnect();

            return self::FAILURE;
        }

        $exists = trim($ssh->exec('[ -d '.escapeshellarg($base).' ] && echo yes || echo no')['output'] ?? '');
        if ($exists !== 'yes') {
            $this->error("Deploy path {$base} does not exist on the server.");
            $ssh->disconnect();

            return self::FAILURE;
        }

        $steps = [
            'Create layout' => sprintf('mkdir -p %s %s %s %s',
                escapeshellarg($base.'/releases'),
                escapeshellarg($shared),
                escapeshellarg($shared.'/backups'),
                escapeshellarg($initial),
            ),
            'Move app files into releases/initial' => sprintf(
                // Everything except the new directories themselves.
                'find %s -mindepth 1 -maxdepth 1 ! -name releases ! -name shared -exec mv -t %s {} +',
                escapeshellarg($base), escapeshellarg($initial),
            ),
            'Move storage/ into shared/' => sprintf(
                'if [ -d %s ]; then mv %s %s; else mkdir -p %s; fi',
                escapeshellarg($initial.'/storage'),
                escapeshellarg($initial.'/storage'),
                escapeshellarg($shared.'/storage'),
                escapeshellarg($shared.'/storage'),
            ),
            'Move .env into shared/' => sprintf(
                'if [ -f %s ]; then mv %s %s; fi',
                escapeshellarg($initial.'/.env'),
                escapeshellarg($initial.'/.env'),
                escapeshellarg($shared.'/.env'),
            ),
            'Link release → shared' => sprintf(
                'ln -sfn %s %s && ln -sfn %s %s',
                escapeshellarg($shared.'/storage'), escapeshellarg($initial.'/storage'),
                escapeshellarg($shared.'/.env'), escapeshellarg($initial.'/.env'),
            ),
            'Create current symlink' => sprintf(
                'ln -sfn %s %s',
                escapeshellarg($initial), escapeshellarg($base.'/current'),
            ),
        ];

        if ($dryRun) {
            $this->comment('DRY RUN — nothing will be changed.');
            $this->newLine();
            foreach ($steps as $label => $cmd) {
                $this->line("  <fg=cyan>{$label}</>");
                $this->line("    {$cmd}");
            }
            $this->newLine();
            $this->printPanelInstructions($site, $base);
            $ssh->disconnect();

            return self::SUCCESS;
        }

        $this->warn('This moves the live site\'s files. Traffic will 404 until you repoint the web root.');
        if (! $this->confirm('Continue?', false)) {
            $ssh->disconnect();

            return self::FAILURE;
        }

        foreach ($steps as $label => $cmd) {
            $result = $ssh->exec($cmd.' 2>&1');
            $exit = $result['exit_code'] ?? 1;

            if ($exit !== 0) {
                $this->error("FAILED at: {$label}");
                $this->line(trim($result['output'] ?? ''));
                $this->newLine();
                $this->error('The site may be in a half-converted state — check the server before deploying.');
                $ssh->disconnect();

                return self::FAILURE;
            }

            $this->line("  <fg=green>✓</> {$label}");
        }

        $site->update(['release_mode' => 'atomic']);
        $ssh->disconnect();

        $this->newLine();
        $this->info('Converted. release_mode is now "atomic".');
        $this->newLine();
        $this->printPanelInstructions($site, $base);

        return self::SUCCESS;
    }

    private function printPanelInstructions(Site $site, string $base): void
    {
        $root = $base.'/current/public';

        $this->comment('REQUIRED — repoint the web root, or the site will 404:');
        $this->newLine();

        match ($site->server->panel_type) {
            'cpanel' => $this->line(
                "  cPanel: point the domain's document root at\n    {$root}\n"
                ."  If the docroot cannot be changed on this plan, make public_html a\n"
                .'  symlink to it — or keep this site on in_place.'
            ),
            'openpanel' => $this->line("  OpenPanel: set the site's document root to\n    {$root}"),
            default => $this->line("  aaPanel: Website → {$site->name} → Site Directory →\n    set the running directory so the docroot is\n    {$root}"),
        };

        $this->newLine();
        $this->comment('Also recommended:');
        $this->line('  • nginx: use $realpath_root instead of $document_root for SCRIPT_FILENAME,');
        $this->line('    so opcache follows the symlink swap instead of caching the old release.');
        $this->line('  • Set the site\'s "Reload command" (e.g. sudo -n systemctl reload php8.3-fpm)');
        $this->line('    so opcache is cleared on every switch.');
    }
}
