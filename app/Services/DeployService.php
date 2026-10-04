<?php

namespace App\Services;

use App\Events\DeploymentPhaseCompleted;
use App\Models\DeployLog;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class DeployService
{
    private SshService $ssh;

    private ClaudeAgentService $claude;

    private RollbackService $rollback;

    private Deployment $deployment;

    private Server $server;

    private Site $site;

    private ?string $gitTempPath = null;

    /**
     * True once `artisan down` has run and `artisan up` has not yet.
     *
     * Phase 4 puts the site into maintenance at step 15 and only lifts it at
     * step 23. Any throw in between (a failing migration is the common one)
     * used to skip step 23 entirely and leave the site showing the 503 page
     * until someone SSH'd in by hand. run()'s catch block uses this flag to
     * guarantee the site comes back up even when the deploy fails.
     */
    private bool $maintenanceOn = false;

    /**
     * Where this deploy writes code.
     *
     * Atomic sites (Phase 2) build into releases/{deployment_id} and only flip
     * the `current` symlink once everything succeeds. in_place sites write
     * straight into deploy_path exactly as before, so this stays null for them
     * and every path helper falls back to the old behaviour.
     */
    private ?string $releasePath = null;

    private ?AtomicReleaseService $atomic = null;

    /**
     * DB driver for the SITE being deployed, read from its own .env content.
     * Defaults to mysql when unset — matches Laravel's own default.
     */
    private function siteDbDriver(): string
    {
        foreach (explode('
', (string) $this->site->env_content) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'DB_CONNECTION=')) {
                $value = strtolower(trim(substr($line, strlen('DB_CONNECTION=')), " 	
\"'"));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return 'mysql';
    }

    /**
     * Drop deprecation and warning chatter from command output before logging.
     *
     * This used to be `| grep -v 'Deprecat' | grep -v 'PHP Warning'` in the
     * shell, where it replaced the command's exit status with grep's — which is
     * how a failed composer install came back as success (R3). Filtering here
     * leaves the exit code untouched.
     */
    private function filterNoise(string $output): string
    {
        // Split on \n and strip any \r — remote output may arrive CRLF, and a
        // literal line break in this source file would itself be CRLF on
        // Windows, which silently matched nothing and ate the whole output.
        $kept = array_filter(
            preg_split('/\r?\n/', $output) ?: [],
            fn ($line) => ! str_contains($line, 'Deprecat') && ! str_contains($line, 'PHP Warning'),
        );

        return trim(implode("\n", $kept));
    }

    /** Code directory for this deploy: the new release, or deploy_path. */
    private function targetPath(): string
    {
        return $this->releasePath ?? $this->site->deploy_path;
    }

    /**
     * Directory holding runtime state (storage/, .env). Atomic sites keep these
     * in shared/ so they outlive any single release.
     */
    private function statePath(): string
    {
        return $this->site->isAtomic()
            ? $this->site->sharedPath()
            : rtrim($this->site->deploy_path, '/');
    }

    /**
     * Run a local build command and fail loudly if it fails.
     *
     * shell_exec() returns only stdout and discards the exit code, so a failed
     * `npm run build` or `composer install` used to be logged as success and
     * shipped a broken (or stale) build to production. Process gives us the
     * exit status, so a build failure now stops the deploy before upload.
     */
    private function runLocal(
        int $step,
        string $label,
        string $command,
        ?string $workingDir = null,
        int $timeout = 1800,
    ): string {
        $pending = Process::timeout($timeout);

        if ($workingDir !== null) {
            $pending = $pending->path($workingDir);
        }

        $result = $pending->run($command);
        $output = trim($result->output()."\n".$result->errorOutput());
        $exit = $result->exitCode();

        if ($result->failed()) {
            $this->log(2, $step, 'error', $label, $output, $exit);

            throw new RuntimeException(
                "Local build step failed (exit {$exit}): {$label}\n".$output
            );
        }

        $this->log(2, $step, 'success', $label, $output, $exit);

        return $output;
    }

    /**
     * Resolve the local composer command. On Windows (Laragon) `composer` may
     * not be in PATH when running from a queue worker, so we fall back to
     * invoking composer.phar directly via the current PHP binary.
     */
    private function getComposerCommand(): string
    {
        // Probe only — a non-zero exit here is an expected outcome (composer
        // not on PATH), not a deploy failure, so this one stays unchecked.
        $check = Process::timeout(30)->run('composer --version');
        if ($check->successful() && str_contains($check->output(), 'Composer version')) {
            return 'composer';
        }

        // Laragon: use php + composer.phar
        $pharPaths = [
            'C:\\laragon\\bin\\composer\\composer.phar',
            'C:\\laragon\\bin\\composer.phar',
        ];
        foreach ($pharPaths as $phar) {
            if (file_exists($phar)) {
                return escapeshellarg(PHP_BINARY).' '.escapeshellarg($phar);
            }
        }

        // Last resort — hope it's in PATH
        return 'composer';
    }

    public function __construct(
        ClaudeAgentService $claude,
        RollbackService $rollback
    ) {
        $this->claude = $claude;
        $this->rollback = $rollback;
    }

    public function run(Deployment $deployment): void
    {
        $this->deployment = $deployment;
        $this->site = $deployment->site;
        $this->server = $deployment->server ?? $this->site->server;
        $this->ssh = new SshService($this->server);

        $deployment->markStarted();

        try {
            $this->phase1PreFlight();

            // Phase 2 (atomic): prepare releases/ + shared/ and record what
            // `current` points at, so a failure can swap straight back to it.
            if ($this->site->isAtomic()) {
                $this->beginAtomicRelease();
            }

            // Three deploy modes:
            // Mode B: source_path set → build from local folder, zip, upload
            // Mode C: repo_url set   → clone from git, build, zip, upload
            // Mode A: neither set    → remote commands only (code already on server)
            if ($this->site->source_path) {
                $this->phase2BuildAssets();

                // R4 (in_place): audit AFTER the local build — so migration
                // files exist to inspect — and BEFORE the upload overwrites the
                // live directory. There is no staging area in in_place mode, so
                // this is the last point where stopping changes nothing.
                if (! $this->site->isAtomic() && $this->auditRequiresApproval()) {
                    $this->broadcastPhase(2, 'Awaiting approval');

                    return;
                }

                $this->phase3Upload();
            } elseif ($this->site->repo_url) {
                $this->phase2CloneAndBuild();

                if (! $this->site->isAtomic() && $this->auditRequiresApproval()) {
                    $this->broadcastPhase(2, 'Awaiting approval');

                    return;
                }

                $this->phase3Upload();
            } else {
                $this->log(2, 7, 'info', null,
                    'Phase 2 & 3 skipped — no source_path or repo_url set. '.
                    'Running remote commands only on existing server code.');

                // Mode A: no local source, so there are no new migration files
                // to inspect — the env-key half of the audit still applies.
                if (! $this->site->isAtomic() && $this->auditRequiresApproval()) {
                    $this->broadcastPhase(2, 'Awaiting approval');

                    return;
                }
            }

            // Phase 4a — build the release: link shared, composer, discover.
            $this->phase4RemoteCommands();

            // R4 (atomic): the audit runs HERE. The release is prepared, so
            // `migrate:status` inside it lists the migrations THIS deploy adds —
            // which is what the old pre-flight placement could never see, since
            // it queried `current` (the old code) before anything was uploaded.
            // Nothing has been migrated and `current` is untouched, so holding
            // the deploy here leaves the live site exactly as it was.
            if ($this->site->isAtomic() && $this->auditRequiresApproval()) {
                $this->log(2, 27, 'info', null, sprintf(
                    'Prepared release %s kept on disk for approval. The live site is unchanged. '
                    .'Approving re-runs this deployment and reuses the same release directory.',
                    basename((string) $this->releasePath),
                ));
                $this->broadcastPhase(4, 'Awaiting approval');

                return;
            }

            // Phase 4b — pre-migrate, migrate, caches, up.
            $this->phase4Migrate();

            // The live site still serves the OLD release until this point.
            if ($this->site->isAtomic()) {
                $this->completeAtomicRelease();

                // R2: only now is `current` the new release, so workers
                // restarted here pick up the new code. Pointed at current/ so
                // they are not pinned to this release on the next deploy.
                $this->restartQueues($this->site->currentPath());
            }

            $healthy = $this->phase5HealthCheck();

            if (! $healthy) {
                $lastErrorLog = $this->getRemoteErrorLog();
                $this->phase6Rollback($lastErrorLog);
            } else {
                $deployment->markFinished('success');

                if ($this->site->isAtomic()) {
                    $this->log(2, 29, 'info', null,
                        $this->atomic()->prune($this->site, $this->deployment));
                }
            }
        } catch (\Throwable $e) {
            $this->log(0, 0, 'error', null, $e->getMessage());
            $this->liftMaintenance();
            // An atomic failure before the switch leaves `current` untouched,
            // so the live site was never affected — say so explicitly.
            $this->reportAtomicFailureState();
            $deployment->markFinished('failed');
            throw $e;
        } finally {
            $this->ssh->disconnect();
            $this->cleanupGitTemp();
            $this->restoreLocalDevDeps();
        }
    }

    /**
     * Restore local dev dependencies after a Mode B build.
     *
     * Mode B runs `composer install --no-dev` directly in the user's source
     * folder to produce a production vendor/ for the zip. That strips dev-only
     * packages (collision, phpunit, …) and breaks the developer's local
     * `artisan serve` with "CollisionServiceProvider not found". Re-install with
     * dev deps so their working copy keeps running. Runs in finally (so it also
     * restores after a failed deploy) and is best-effort — never fails a deploy.
     * Guarded to Mode B: source_path must be set (skips Mode A and temp clones).
     */
    private function restoreLocalDevDeps(): void
    {
        // vendor/ is no longer built locally — composer install runs on the server
        // in Phase 4. Nothing to restore.
    }

    // --- Phase 1 ---
    private function phase1PreFlight(): void
    {
        $this->log(1, 1, 'info', null, 'Phase 1: Pre-flight checks');

        // Step 1: Validate server profile
        if (empty($this->server->host) || empty($this->server->ssh_user)) {
            throw new RuntimeException('Invalid server profile: missing host or SSH user.');
        }
        $this->log(1, 1, 'success', null, 'Server profile valid');

        // Step 2: Test SSH
        $this->ssh->connect();
        $this->log(1, 2, 'success', null, 'SSH connected to '.$this->server->host);

        // Step 3: Check disk space
        $freeMb = $this->ssh->getDiskFreeSpace($this->site->deploy_path);
        $minMb = config('autopilot.deploy.disk_warning_mb', 500);
        $status = $freeMb >= $minMb ? 'success' : 'warning';
        $this->log(1, 3, $status, null, "Disk free: {$freeMb}MB (minimum {$minMb}MB)");

        // Step 4: Check PHP version
        $phpVersion = $this->ssh->getPhpVersion();
        $minPhp = config('autopilot.deploy.min_php_version', '8.2');
        $phpOk = version_compare($phpVersion, $minPhp, '>=');
        $this->log(1, 4, $phpOk ? 'success' : 'warning', null, "PHP: {$phpVersion}");

        // Step 5: Check the database client the SITE actually uses (F11).
        // This used to hardcode `mysql --version`, which warns misleadingly on a
        // Postgres site. The driver comes from the site's own env_content.
        $driver = $this->siteDbDriver();
        $probe = $driver === 'pgsql' ? 'psql --version' : 'mysql --version';
        $needle = $driver === 'pgsql' ? 'psql' : 'mysql';
        $result = $this->ssh->exec($probe.' 2>&1');
        $this->log(1, 5, str_contains(strtolower($result['output'] ?? ''), $needle) ? 'success' : 'warning',
            $probe, trim($result['output'] ?? ''));

        // Step 6: Record the rollback target.
        // The old `git rev-parse HEAD` ran against a deploy path that has no
        // .git (code arrives by zip), so it always logged "no-git". Atomic sites
        // record previous_release instead, in beginAtomicRelease().
        if (! $this->site->isAtomic()) {
            $this->log(1, 6, 'info', null,
                'Site is in_place — no previous release is retained, so this deploy cannot be '
                .'rolled back. Convert it with `php artisan deploy:convert-atomic`.');
        }

        $this->broadcastPhase(1, 'Pre-flight complete');
    }

    // --- Phase 2 ---
    private function phase2BuildAssets(): void
    {
        $this->log(2, 7, 'info', null, 'Phase 2: Building assets locally');

        $base = $this->getSourcePath();
        $this->log(2, 7, 'info', null, 'Building from source: '.$base);

        // Step 7: npm run build — throws on non-zero exit, so a broken build
        // never reaches phase 3.
        $this->runLocal(7, 'npm run build', 'npm run build', $base);

        // Step 8: vendor/ is excluded from the zip and installed on the server
        // in Phase 4 (composer install --no-dev). Skip local composer install
        // to avoid stripping dev deps from the developer's working copy.
        $this->log(2, 8, 'success', null, 'Skipping local composer install — vendor/ installed on server in Phase 4.');

        // Step 9: Verify build output (only if project has a public/build/ target)
        $buildPath = $base.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        if (is_dir($buildPath) && count(scandir($buildPath)) > 2) {
            $this->log(2, 9, 'success', null, 'Build assets verified at '.$buildPath);
        } else {
            $this->log(2, 9, 'warning', null, 'No Vite build output found at '.$buildPath.' — continuing anyway');
        }

        $this->broadcastPhase(2, 'Assets built');
    }

    // --- Phase 2 (Mode C) ---
    private function phase2CloneAndBuild(): void
    {
        $this->log(2, 7, 'info', null, 'Phase 2: Cloning repo and building assets');

        // Verify git is available (probe — handled explicitly below)
        $gitVersion = Process::timeout(30)->run('git --version');
        if (! $gitVersion->successful() || ! str_contains($gitVersion->output(), 'git version')) {
            throw new RuntimeException('git is not installed on this machine. Install git to use repo-based deploys.');
        }

        $branch = $this->site->branch ?: 'main';
        $repoUrl = $this->site->repo_url;
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'autopilot_git_'.$this->deployment->id;

        // Clean up any stale temp from a previous failed run
        if (is_dir($tempDir)) {
            $this->deleteDirectory($tempDir);
        }
        mkdir($tempDir, 0755, true);

        // Clone (shallow — faster, less disk)
        $cloneCmd = sprintf(
            'git clone --depth 1 --branch %s %s %s',
            escapeshellarg($branch),
            escapeshellarg($repoUrl),
            escapeshellarg($tempDir)
        );
        // A failed clone (bad branch, auth) used to fall through to the .git
        // check below with the real git error discarded; now it surfaces.
        $this->runLocal(7, $cloneCmd, $cloneCmd);

        if (! is_dir($tempDir.DIRECTORY_SEPARATOR.'.git')) {
            throw new RuntimeException("Git clone failed — .git directory not found in {$tempDir}");
        }

        $this->gitTempPath = $tempDir;

        // npm install + build (cloned repo has no node_modules)
        $this->runLocal(7, 'npm install && npm run build', 'npm install && npm run build', $tempDir);

        // composer install (--no-scripts to avoid artisan package:discover DB errors)
        $composerCmd = $this->getComposerCommand();
        $composerArgs = 'install --no-dev --no-scripts --optimize-autoloader '
            .'--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix';
        $this->runLocal(8, "composer {$composerArgs}", "{$composerCmd} {$composerArgs}", $tempDir);

        // Verify build output
        $buildPath = $tempDir.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'build';
        if (is_dir($buildPath) && count(scandir($buildPath)) > 2) {
            $this->log(2, 9, 'success', null, 'Build assets verified at '.$buildPath);
        } else {
            $this->log(2, 9, 'warning', null, 'No Vite build output found — continuing anyway');
        }

        $this->broadcastPhase(2, 'Repo cloned and assets built');
    }

    /**
     * Absolute local path to the source code that should be zipped and deployed.
     * Returns gitTempPath for Mode C, source_path for Mode B, or base_path() as fallback.
     */
    private function getSourcePath(): string
    {
        if ($this->gitTempPath) {
            return $this->gitTempPath;
        }

        $path = $this->site->source_path ?: base_path();

        if (! is_dir($path)) {
            throw new RuntimeException(
                "Source path does not exist: {$path}. ".
                "Set 'Source Path' on the site to the local folder of the project you want to deploy."
            );
        }

        return rtrim($path, '/\\');
    }

    private function cleanupGitTemp(): void
    {
        if ($this->gitTempPath && is_dir($this->gitTempPath)) {
            $this->deleteDirectory($this->gitTempPath);
            $this->gitTempPath = null;
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    // --- Phase 3 ---
    private function phase3Upload(): void
    {
        $this->log(3, 10, 'info', null, 'Phase 3: Uploading to server');

        // Step 10: Build zip archive locally (no rsync — works on Windows + Linux)
        $zipPath = storage_path('app/deploy_'.$this->deployment->id.'.zip');
        $zipLog = DeployLog::record($this->deployment->id, 3, 10, 'info', null, 'Creating archive (0%)...');
        $this->createZipArchive($zipPath, function ($pct, $fileCount) use ($zipLog) {
            $zipLog->forceFill([
                'output' => "Creating archive ({$pct}%) — {$fileCount} files processed",
                'logged_at' => now(),
            ])->save();
        });
        $size = round(filesize($zipPath) / 1024 / 1024, 1);
        $zipLog->forceFill([
            'status' => 'success',
            'output' => "Archive created: {$size}MB",
            'logged_at' => now(),
        ])->save();

        // Step 11: Upload via SFTP. We create a single "progress" log row up
        // front and update its `output` in place on every progress tick so the
        // live terminal shows real-time upload percentage instead of going
        // silent for several minutes on slow links.
        $remoteTmp = '/tmp/autopilot_deploy_'.$this->deployment->id.'.zip';
        $progressLog = DeployLog::record(
            $this->deployment->id, 3, 11, 'info', null,
            "Uploading 0/{$size}MB (0%)..."
        );
        $onProgress = function ($sent, $total, $rateMbps, $pct) use ($progressLog) {
            $sentMb = round($sent / 1024 / 1024, 1);
            $totalMb = $total > 0 ? round($total / 1024 / 1024, 1) : 0;
            $progressLog->forceFill([
                'output' => "Uploading {$sentMb}/{$totalMb}MB ({$pct}%) at {$rateMbps} MB/s",
                'logged_at' => now(),
            ])->save();
        };
        $this->ssh->uploadFile($zipPath, $remoteTmp, $onProgress);
        @unlink($zipPath);
        $progressLog->forceFill([
            'status' => 'success',
            'output' => "Archive uploaded via SFTP ({$size}MB)",
            'logged_at' => now(),
        ])->save();

        // Reconnect SSH to get a clean channel after SFTP
        $this->ssh->reconnect();

        // Step 12: Extract + cleanup in one single exec to avoid channel-reuse errors
        // Atomic sites extract into releases/{id}; in_place writes deploy_path.
        $deployPath = $this->targetPath();
        $path = escapeshellarg($deployPath);
        $remoteTmpQ = escapeshellarg($remoteTmp);
        // A non-root SSH user can't overwrite files the previous deploy handed to
        // the web user (Step 13b / 235), so reclaim ownership first — needs the
        // NOPASSWD chown/chmod sudoers rule (see asRoot()).
        $reclaim = $this->server->ssh_user !== 'root'
            ? 'sudo -n chown -R '.escapeshellarg($this->server->ssh_user.($this->webUser() ? ':'.$this->webUser() : ''))." {$path} 2>/dev/null; "
            : '';
        $result = $this->ssh->exec(
            "mkdir -p {$path} 2>/dev/null; {$reclaim}".
            "rm -rf {$deployPath}/vendor/laravel/pail {$deployPath}/vendor/laravel/telescope {$deployPath}/vendor/barryvdh/laravel-debugbar 2>/dev/null; ".
            "unzip -oq {$remoteTmpQ} -d {$path} 2>&1 | tail -5; ".
            'UNZIP_EXIT=${PIPESTATUS[0]}; '.
            "rm -f {$remoteTmpQ}; ".
            'echo "EXIT:$UNZIP_EXIT"'
        );
        $exitCode = preg_match('/EXIT:(\d+)/', $result['output'] ?? '', $m) ? (int) $m[1] : 1;
        $detail = $exitCode === 0 ? 'Extracted successfully' : 'Extract failed: '.trim($result['output'] ?? '');
        $this->log(3, 12, $exitCode === 0 ? 'success' : 'error', 'unzip', $detail, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException('Extract failed');
        }

        // Step 12b: Prune stale build assets.
        //
        // unzip -o overwrites and adds, but never deletes — so every deploy that
        // rebuilds the frontend leaves the previous build's hashed files behind
        // in public/build/assets, and the folder grows without bound. This keeps
        // only the files the current manifest references and removes the rest.
        //
        // Deliberately conservative: it runs only when a non-empty manifest.json
        // is present, and a file is deleted only when its basename appears in
        // NEITHER manifest.json nor .vite/manifest.json — which, right after a
        // fresh extract, is true only of assets from older builds. If anything
        // looks off it deletes nothing and the deploy proceeds.
        $buildDir = escapeshellarg($deployPath.'/public/build');
        $prune = $this->ssh->exec(
            "B={$buildDir}; ".
            'A="$B/assets"; '.
            'M="$B/manifest.json"; V="$B/.vite/manifest.json"; '.
            'if [ -d "$A" ] && [ -s "$M" ]; then '.
            '  KEEP=$(grep -ohaE "assets/[A-Za-z0-9_.-]+" "$M" "$V" 2>/dev/null | sed "s#assets/##" | sort -u); '.
            '  if [ -n "$KEEP" ]; then '.
            '    removed=0; '.
            '    for f in "$A"/*; do [ -e "$f" ] || continue; b=$(basename "$f"); '.
            '      printf "%s\n" "$KEEP" | grep -qxF "$b" || { rm -f "$f"; removed=$((removed+1)); }; '.
            '    done; '.
            '    echo "PRUNED:$removed"; '.
            '  else echo "PRUNED:skip-no-refs"; fi; '.
            'else echo "PRUNED:skip-no-manifest"; fi'
        );
        $this->log(3, 12, 'success', 'prune stale build assets', trim($prune['output'] ?? ''));

        // Reconnect before chmod (fresh channel)
        $this->ssh->reconnect();

        // Step 13: Ensure framework runtime dirs exist, then set permissions in one exec call
        $writableDirs = config('autopilot.deploy.writable_dirs', []);
        $ensureDirs = [
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ];
        $mkdirCmd = '';
        foreach ($ensureDirs as $dir) {
            $mkdirCmd .= "mkdir -p {$path}/".escapeshellarg($dir).' && ';
        }
        $chmodExtra = '';
        foreach ($writableDirs as $dir) {
            $chmodExtra .= " && chmod -R 775 {$path}/".escapeshellarg($dir);
        }
        $this->ssh->exec(
            $mkdirCmd.
            "find {$path} -type d -exec chmod 755 {} \\; && ".
            "find {$path} -type f -exec chmod 644 {} \\;".
            $chmodExtra
        );
        $this->log(3, 13, 'success', null, 'Runtime dirs ensured + permissions set (755/644, writable dirs 775)');

        // Step 13b: chown so the web server (PHP-FPM) can write to storage/
        // and bootstrap/cache/. Without this, Laravel silently fails on a
        // freshly-uploaded root-owned deploy → blank page.
        $webUser = $this->webUser();

        if ($webUser) {
            $this->ssh->reconnect();
            // Phase 4 still writes .env and runs artisan as the SSH user, so a
            // non-root deployer keeps ownership here (group = web user, dirs are
            // group-writable); the final chown after artisan (step 235) hands
            // everything to the web user.
            $ownerUser = $this->server->ssh_user === 'root' ? $webUser : $this->server->ssh_user;
            $owner = escapeshellarg($ownerUser.':'.$webUser);
            $this->ssh->exec($this->asRoot("chown -R {$owner} {$path}"));
            $this->log(3, 13, 'success', null, "Ownership set to {$ownerUser}:{$webUser} on {$this->site->deploy_path}");
        } else {
            $this->log(3, 13, 'warning', null,
                'No web_user configured on server — skipping chown. '.
                'Laravel may fail to write storage/ or bootstrap/cache/. '.
                'Edit the server and set "Web User" (aaPanel: www, cPanel/OpenPanel: your account user).');
        }

        // Step 13c: Sync storage/app/public/ (uploaded media) when deploying with
        // +Data (seeders may reference seed images) OR +DB (the pushed database
        // references uploaded files — they must ship together, or images 404).
        // Kept separate from the main zip because this folder normally holds user
        // uploads that must survive ordinary redeploys.
        if ($this->deployment->with_data || $this->deployment->with_database) {
            $this->syncStorageAppPublic($webUser);
        }

        $this->broadcastPhase(3, 'Upload complete');
    }

    /**
     * OS user PHP-FPM runs as (aaPanel defaults to www).
     */
    private function webUser(): ?string
    {
        return $this->server->web_user
            ?: ($this->server->panel_type === 'aapanel' ? 'www' : null);
    }

    /**
     * Ownership/permission commands need root. When the SSH user is not root they
     * go through passwordless sudo — the server needs a sudoers rule such as
     *   deployuser ALL=(root) NOPASSWD: /usr/bin/chown, /usr/bin/chmod
     */
    private function asRoot(string $command): string
    {
        return $this->server->ssh_user === 'root' ? $command : "sudo -n {$command}";
    }

    private function syncStorageAppPublic(?string $webUser): void
    {
        $localDir = rtrim($this->getSourcePath(), '/\\').DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'public';
        if (! is_dir($localDir)) {
            $this->log(3, 13, 'info', null, 'No local storage/app/public/ — skipping asset sync.');

            return;
        }

        $assetZip = storage_path('app/tmp_assets_'.$this->deployment->id.'.zip');
        @mkdir(dirname($assetZip), 0775, true);

        $zip = new \ZipArchive;
        if ($zip->open($assetZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->log(3, 13, 'warning', null, 'Could not create asset zip — skipping.');

            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($localDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        $count = 0;
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($localDir) + 1));
            if (str_starts_with($rel, 'livewire-tmp/')) {
                continue;
            }
            $zip->addFile($file->getRealPath(), $rel);
            $count++;
        }
        $zip->close();

        if ($count === 0) {
            @unlink($assetZip);
            $this->log(3, 13, 'info', null, 'storage/app/public/ is empty — skipping asset sync.');

            return;
        }

        $this->log(3, 13, 'info', null, "Uploading {$count} seed asset(s) from storage/app/public/");

        // This upload opens a brand-new SFTP connection right after several rapid
        // SSH reconnects (unzip/chmod/chown above). Some hosts briefly rate-limit
        // new connections from the same IP in that situation — a short pause here
        // avoids landing in that window (uploadFile() itself also retries/backs off).
        usleep(500_000);

        $remoteTmp = '/tmp/autopilot_assets_'.$this->deployment->id.'.zip';
        $this->ssh->uploadFile($assetZip, $remoteTmp);
        @unlink($assetZip);
        $this->ssh->reconnect();

        $remoteTarget = escapeshellarg($this->statePath().'/storage/app/public');
        $remoteTmpQ = escapeshellarg($remoteTmp);
        $this->ssh->exec(
            "mkdir -p {$remoteTarget} && ".
            "unzip -oq {$remoteTmpQ} -d {$remoteTarget} > /dev/null 2>&1; ".
            "rm -f {$remoteTmpQ}"
        );

        if ($webUser) {
            $this->ssh->reconnect();
            $owner = escapeshellarg($webUser.':'.$webUser);
            $this->ssh->exec($this->asRoot("chown -R {$owner} {$remoteTarget}").' && '.$this->asRoot("chmod -R 775 {$remoteTarget}"));
        }

        $this->log(3, 13, 'success', null, "Synced {$count} file(s) to storage/app/public/");
    }

    private function createZipArchive(string $zipPath, ?callable $onProgress = null): void
    {
        $exclude = config('autopilot.deploy.excluded_paths', [
            '.env', 'node_modules', '.git', 'storage/logs', 'tests',
            // bootstrap/cache contains LOCAL Windows paths in services.php / packages.php.
            // Shipping these to a Linux server breaks provider boot and package discovery.
            'bootstrap/cache',
            'storage/framework/cache',
            'storage/framework/sessions',
            'storage/framework/views',
            'public/hot',
            // vendor/ is excluded to keep the zip small for reliable upload over slow connections.
            // composer install --no-dev runs on the server in Phase 4 instead.
            'vendor',
        ]);

        $baseDir = $this->getSourcePath();
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip archive at '.$zipPath);
        }

        // Collect files first to know total count for progress
        $allFiles = [];
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iter as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();
            $relativePath = str_replace('\\', '/', substr($realPath, strlen($baseDir) + 1));

            $skip = false;
            foreach ($exclude as $pattern) {
                $pattern = ltrim(str_replace('\\', '/', $pattern), '/');
                if (str_starts_with($relativePath, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if (! $skip) {
                $allFiles[] = [$realPath, $relativePath];
            }
        }

        $total = count($allFiles);
        $lastPct = -1;
        $lastUpdate = 0;

        foreach ($allFiles as $i => [$realPath, $relativePath]) {
            $zip->addFile($realPath, $relativePath);

            if ($onProgress && $total > 0) {
                $pct = (int) round(($i + 1) * 100 / $total);
                $now = time();
                // Update every 2% or every 2 seconds
                if ($pct >= $lastPct + 2 || $now > $lastUpdate + 2) {
                    $onProgress($pct, $i + 1);
                    $lastPct = $pct;
                    $lastUpdate = $now;
                }
            }
        }

        $zip->close();
    }

    // --- Phase 4 ---
    private function phase4RemoteCommands(): void
    {
        $this->log(4, 14, 'info', null, 'Phase 4: Running remote commands');

        // Write .env to remote server before any artisan commands
        if ($this->site->env_content) {
            // Atomic: .env lives in shared/ and the release symlinks to it.
            $envPath = $this->statePath().'/.env';
            $this->ssh->uploadContent($this->site->env_content, $envPath);
            $this->log(4, 14, 'success', null, '.env written to '.$envPath.' (credentials masked)');
        } else {
            $this->log(4, 14, 'warning', null, 'No .env configured for this site — skipping .env write. Migrations may fail.');
        }

        // Reconnect SSH to get a fresh exec channel after SFTP uploadContent()
        // (phpseclib SSH2 can fail with "Please close the channel" otherwise).
        $this->ssh->reconnect();

        // Atomic: point the release at shared/.env and shared/storage before any
        // artisan command runs — they all need a readable .env, and the release's
        // own storage/ must not shadow the shared one.
        if ($this->site->isAtomic()) {
            $this->atomic()->linkShared($this->site, $this->releasePath);
            $this->log(4, 14, 'success', null,
                'Release linked to shared/.env and shared/storage.');
        }

        // Optional: grant CREATEDB to the DB user so stancl/tenancy can auto-create
        // tenant databases. Parses DB_USERNAME from the site's env_content.
        // Only needs to run once but is safe to re-run (idempotent ALTER USER).
        if ($this->site->grant_createdb && $this->site->env_content) {
            $dbUser = null;
            foreach (explode("\n", $this->site->env_content) as $line) {
                $line = trim($line);
                if (str_starts_with($line, 'DB_USERNAME=')) {
                    $dbUser = trim(substr($line, strlen('DB_USERNAME=')), " \t\r\n\"'");
                    break;
                }
            }

            // F12: escapeshellarg() wraps the value in SINGLE quotes, which was
            // then embedded inside a double-quoted SQL string — producing
            // ALTER USER 'name' CREATEDB, invalid in Postgres (identifiers take
            // double quotes), and mangling the shell quoting besides.
            //
            // Validate the identifier instead, then build both layers by hand.
            if ($dbUser && ! preg_match('/^[A-Za-z0-9_]+$/', $dbUser)) {
                $this->log(4, 161, 'warning', null,
                    'Skipping grant_createdb — DB_USERNAME contains characters outside '
                    .'[A-Za-z0-9_] and cannot be safely interpolated into SQL.');
                $dbUser = null;
            }

            if ($dbUser) {
                // Safe: $dbUser is known to match ^[A-Za-z0-9_]+$.
                $sql = sprintf('ALTER USER "%s" CREATEDB;', $dbUser);
                $grantCmd = 'psql -U postgres -c '.escapeshellarg($sql).' 2>&1';
                $grantResult = $this->ssh->exec($grantCmd);
                $grantExit = $grantResult['exit_code'] ?? 1;
                $this->log(4, 14, $grantExit === 0 ? 'success' : 'warning',
                    'psql -U postgres -c "ALTER USER [DB_USERNAME] CREATEDB;"',
                    $grantResult['output'] ?? '', $grantExit);
            } else {
                $this->log(4, 14, 'warning', null, 'grant_createdb: could not parse DB_USERNAME from .env — skipping.');
            }
        }

        $php = escapeshellarg($this->site->php_binary);
        $path = escapeshellarg($this->targetPath());
        $token = bin2hex(random_bytes(16));

        // Run `down` + clear stale caches first.
        //
        // The raw `rm` MUST come first: a stale bootstrap/cache/packages.php or
        // services.php (e.g. left from a previous version that used a package the
        // current vendor/ no longer ships) poisons the compiled manifest. Every
        // artisan command — including `optimize:clear` — boots the framework, which
        // loads that manifest and dies with "Class ... ServiceProvider not found"
        // before it can clear anything. Deleting the compiled files directly (no
        // framework boot) lets the app boot fresh and regenerate the manifest.
        $isAtomic = $this->site->isAtomic();

        // Atomic deploys build a release the live site isn't serving yet, so
        // there is nothing to take down — the old release keeps answering until
        // the symlink flips. Sites with destructive migrations opt back in via
        // maintenance_on_migrate, but even then the window must not open here:
        // the risk audit runs between phase 4a and 4b and can return without
        // reaching `artisan up`, which would strand the live site on the 503
        // page until somebody approved. Atomic sites go down at the start of
        // phase4Migrate() instead, past that gate.
        $useMaintenance = ! $isAtomic;

        // R3: composer must be able to FAIL. The old pipeline ended in
        // `| grep -v … || true`, so a failed install exited 0 and a release with
        // a missing or partial vendor/ was switched live. Noise filtering moved
        // into PHP (filterNoise) — doing it in the shell is what swallowed the
        // exit code. The single retry stays: a transient composer failure leaves
        // installed.json in a stale dev state, and the retry self-heals it.
        $composer = 'composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-ansi';

        $earlyCommands = [
            149 => "cd {$path} && rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/events.php bootstrap/cache/routes-*.php 2>&1",
            148 => "cd {$path} && ({$composer} 2>&1 || (sleep 5 && {$composer} 2>&1))",
            // Regenerate the package manifest from the fresh --no-dev vendor BEFORE
            // any framework boot. Prevents the "Class ...ServiceProvider not found"
            // boot crash when the cache was cleared but never rebuilt.
            //
            // R3: `|| true` is kept ONLY for in_place, where a non-Laravel site is
            // legitimate. On an atomic site a failed package:discover means the
            // release is broken and must not be switched live.
            147 => "cd {$path} && {$php} artisan package:discover --no-interaction 2>&1".($isAtomic ? '' : ' || true'),
            // R5: optimize:clear includes cache:clear. Atomic sites share storage/
            // (and usually Redis) with the LIVE release, so clearing it here would
            // flush the running site's cache while merely preparing a release.
            150 => $isAtomic
                ? "cd {$path} && {$php} artisan config:clear 2>&1 && {$php} artisan route:clear 2>&1 && {$php} artisan view:clear 2>&1 && {$php} artisan event:clear 2>&1"
                : "cd {$path} && {$php} artisan optimize:clear 2>&1",
        ];

        if ($useMaintenance) {
            $earlyCommands[15] = "cd {$path} && {$php} artisan down --retry=60 --secret={$token} 2>&1";
            ksort($earlyCommands);
        }

        foreach ($earlyCommands as $earlyStep => $earlyCmd) {
            $earlyResult = $this->ssh->exec($earlyCmd);
            $earlyExit = $earlyResult['exit_code'] ?? 1;

            // Noise is stripped here, in PHP, so the exit code above is the real
            // one (R3). Filtering in the shell is what hid composer failures.
            $earlyOutput = $this->filterNoise($earlyResult['output'] ?? '');

            $this->log(4, $earlyStep, $earlyExit === 0 ? 'success' : 'error',
                $earlyCmd, $earlyOutput, $earlyExit);

            // R3: composer is not optional. A release without a complete vendor/
            // is broken, and on an atomic site it must never reach the switch.
            if ($earlyStep === 148 && $earlyExit !== 0) {
                $this->diagnoseFailedStep(4, 148, $earlyCmd, $earlyOutput);

                throw new RuntimeException(
                    'composer install failed — release not activated. '.$earlyOutput
                );
            }

            // package:discover keeps `|| true` on in_place (non-Laravel sites are
            // legitimate there), so a non-zero exit here only happens on atomic.
            if ($earlyStep === 147 && $earlyExit !== 0) {
                $this->diagnoseFailedStep(4, 147, $earlyCmd, $earlyOutput);

                throw new RuntimeException(
                    'package:discover failed — release not activated. '.$earlyOutput
                );
            }

            // From here on the site serves a 503 until step 23 lifts it, so any
            // throw in between must be caught by run() and trigger liftMaintenance().
            if ($earlyStep === 15 && $earlyExit === 0) {
                $this->maintenanceOn = true;
            }
        }

        // R3: prove the release can actually boot before anything irreversible
        // (migrate) or visible (the switch) happens. A vendor/ that installed
        // with exit 0 but is missing the autoloader still cannot run.
        if ($isAtomic) {
            $check = $this->ssh->exec(
                "cd {$path} && test -f vendor/autoload.php && {$php} artisan --version 2>&1"
            );
            $checkExit = $check['exit_code'] ?? 1;

            $this->log(4, 151, $checkExit === 0 ? 'success' : 'error',
                'test -f vendor/autoload.php && artisan --version',
                $this->filterNoise($check['output'] ?? ''), $checkExit);

            if ($checkExit !== 0) {
                throw new RuntimeException(
                    'Release cannot boot (vendor/autoload.php missing or artisan failed) — '
                    .'release not activated. '.$this->filterNoise($check['output'] ?? '')
                );
            }
        }

        $this->broadcastPhase(4, 'Release prepared');
    }

    /**
     * Phase 4b — everything from pre-migrate commands onward.
     *
     * R4: split out of phase4RemoteCommands() so the risk audit can run between
     * the two. The audit needs a prepared release (vendor/ installed, artisan
     * runnable) to list pending migrations, but must happen before `migrate`
     * writes anything or the switch makes it visible.
     */
    private function phase4Migrate(): void
    {
        $path = escapeshellarg($this->targetPath());
        $php = escapeshellarg($this->site->php_binary ?: 'php');

        // Step 15 for atomic sites that asked for a maintenance window. It runs
        // here, not in phase 4a, so the window opens only once the deploy is
        // certain to proceed: the risk audit sits between the two phases and can
        // return early, and that path never reaches `artisan up` — which left the
        // live site stranded on the 503 page until someone approved.
        //
        // storage/ is shared on atomic sites, so the down file written from the
        // release is seen by the release currently serving traffic.
        if ($this->site->isAtomic() && $this->site->maintenance_on_migrate) {
            $downToken = bin2hex(random_bytes(16));
            $downCmd = "cd {$path} && {$php} artisan down --retry=60 --secret={$downToken} 2>&1";

            $downResult = $this->ssh->exec($downCmd);
            $downExit = $downResult['exit_code'] ?? 1;

            $this->log(4, 15, $downExit === 0 ? 'success' : 'error', $downCmd,
                $this->filterNoise($downResult['output'] ?? ''), $downExit);

            // Only once the site is actually down does run()'s catch become
            // responsible for lifting it.
            if ($downExit === 0) {
                $this->maintenanceOn = true;
            }
        }

        // Optional: pre-migrate commands (one per line, e.g. "php artisan tenancy:install").
        // Run before migrate --force so setup commands (publishing migrations, etc.) complete first.
        if (! empty($this->site->pre_migrate_commands)) {
            $lines = array_filter(array_map('trim', explode("\n", $this->site->pre_migrate_commands)));
            foreach ($lines as $i => $line) {
                $cmdResult = $this->ssh->exec("cd {$path} && {$line} 2>&1");
                $cmdExit = $cmdResult['exit_code'] ?? 1;
                $this->log(4, 155 + $i, $cmdExit === 0 ? 'success' : 'warning',
                    $line, $cmdResult['output'] ?? '', $cmdExit);
            }
        }

        if ($this->deployment->with_database) {
            // The DB push is DATA-ONLY (mysqldump --no-create-info, pg data-only) and
            // uses REPLACE/upsert, so the remote SCHEMA must already exist. Run
            // migrate FIRST: on a fresh database it creates the tables; on an existing
            // one it's a no-op. Then push the rows — REPLACE makes it idempotent, so
            // re-deploys overwrite rather than duplicate. (Previously push ran first,
            // which failed with "table doesn't exist" on a brand-new remote database.)
            $migrateResult = $this->ssh->exec("cd {$path} && {$php} artisan migrate --force 2>&1");
            $migrateExit = $migrateResult['exit_code'] ?? 1;
            $this->log(4, 16, $migrateExit === 0 ? 'success' : 'error',
                "cd {$path} && {$php} artisan migrate --force 2>&1",
                $migrateResult['output'] ?? '', $migrateExit);
            if ($migrateExit !== 0) {
                throw new RuntimeException('Migration failed — aborting deploy.');
            }
            $this->pushLocalDatabase();
        } else {
            $migrateResult = $this->ssh->exec("cd {$path} && {$php} artisan migrate --force 2>&1");
            $migrateExit = $migrateResult['exit_code'] ?? 1;
            $this->log(4, 16, $migrateExit === 0 ? 'success' : 'error',
                "cd {$path} && {$php} artisan migrate --force 2>&1",
                $migrateResult['output'] ?? '', $migrateExit);
            if ($migrateExit !== 0) {
                throw new RuntimeException('Migration failed — aborting deploy.');
            }
        }

        $commands = [];

        // Optional: run tenant migrations (stancl/tenancy multi-tenant apps like Alpha eLibrary).
        // Runs `tenants:migrate` on all tenant DBs after the central DB is migrated.
        if ($this->site->run_tenant_migrations) {
            $tenantMigrateResult = $this->ssh->exec("cd {$path} && {$php} artisan tenants:migrate --force 2>&1");
            $tenantMigrateExit = $tenantMigrateResult['exit_code'] ?? 1;
            $this->log(4, 16, $tenantMigrateExit === 0 ? 'success' : 'error',
                "cd {$path} && {$php} artisan tenants:migrate --force 2>&1",
                $tenantMigrateResult['output'] ?? '', $tenantMigrateExit);
            if ($tenantMigrateExit !== 0) {
                throw new RuntimeException('Tenant migrations failed — aborting deploy.');
            }
        }

        // Optional: run seeders when either:
        //  - the site has run_seeders = true (persistent setting), OR
        //  - this specific deployment was triggered with with_data = true (one-off)
        if ($this->site->run_seeders || $this->deployment->with_data) {
            $commands[164] = "cd {$path} && {$php} artisan db:seed --force 2>&1";
        }

        $commands += [
            17 => "cd {$path} && {$php} artisan config:cache 2>&1",
            // Use route:clear instead of route:cache — cached routes can cause
            // 405 Method Not Allowed on POST endpoints with some packages.
            18 => "cd {$path} && {$php} artisan route:clear 2>&1",
            19 => "cd {$path} && {$php} artisan view:cache 2>&1",
            20 => "cd {$path} && {$php} artisan event:cache 2>&1",
            // storage:link via raw `ln -sfn` instead of `php artisan storage:link`.
            // The artisan command uses Laravel's Filesystem::link() which calls exec()
            // internally — many hardened hosts (corapos.com included) have exec disabled
            // in php.ini → "Call to undefined function exec()". The shell ln works always.
            // Use ABSOLUTE target path: relative paths cause "Too many levels of symbolic
            // links" because the resolver treats the link as starting from public/.
            21 => "rm -f {$path}/public/storage 2>/dev/null; ln -s {$path}/storage/app/public {$path}/public/storage && echo 'Symlink ensured: public/storage -> storage/app/public'",
            // Republish package assets so public/vendor/* stays in sync with
            // composer packages after each deploy.
            // Each command is allowed to "fail" (|| true) since not every
            // project installs every package; we only care that the ones
            // present are published.
            // Steps 215/216/217 (livewire:publish, filament:assets) removed in
            // UPGRADE-v2 Phase 1 — this project dropped FilamentPHP, and the
            // commands were `|| true` no-ops costing three SSH round-trips.
            // R2: step 22 moved out for atomic sites — see restartQueues(). Here
            // it would restart workers while `current` still points at the OLD
            // release, pinning them to stale code until the next deploy.

            23 => "cd {$path} && {$php} artisan up 2>&1",
        ];

        // Re-chown the ENTIRE deploy path after all artisan commands.
        // Artisan runs as the SSH user (usually root), so config:cache,
        // route:clear, view:cache, etc. create files owned by root inside
        // storage/ and bootstrap/cache/. PHP-FPM (www) can't write to them
        // → 500 "Permission denied" on the live site.
        if (! $this->site->isAtomic()) {
            // in_place writes over the live directory, so by the time we get
            // here the new code IS the live code — restarting now is correct.
            $commands[22] = "cd {$path} && {$php} artisan queue:restart 2>&1";
        }

        $webUser = $this->webUser();
        if ($webUser) {
            $owner = escapeshellarg($webUser.':'.$webUser);

            // On atomic sites $path is just this release, so chown it plus
            // shared/ — never the whole deploy_path, which holds every retained
            // release and would make each deploy slower than the last.
            $chownTargets = $path;
            if ($this->site->isAtomic()) {
                $chownTargets .= ' '.escapeshellarg($this->site->sharedPath());
            }

            $commands[235] = $this->asRoot("chown -R {$owner} {$chownTargets}").' 2>/dev/null; true';
        }

        ksort($commands);

        foreach ($commands as $step => $cmd) {
            $result = $this->ssh->exec($cmd);
            $status = $result['exit_code'] === 0 ? 'success' : 'error';

            // storage:link is fine if already linked
            if ($step === 21 && str_contains($result['output'], 'already exists')) {
                $status = 'success';
            }

            $this->log(4, $step, $status, $cmd, $result['output'], $result['exit_code']);

            // Site is live again — run()'s catch no longer needs to lift it.
            if ($step === 23 && $status === 'success') {
                $this->maintenanceOn = false;
            }

            // Phase 4: attach an AI diagnosis to any failed step, so the log
            // carries a cause and a suggested fix instead of raw stderr.
            if ($status === 'error') {
                $this->diagnoseFailedStep(4, $step, $cmd, $result['output'] ?? '');
            }

            if ($status === 'error' && in_array($step, [16, 23])) {
                throw new RuntimeException("Critical step {$step} failed: ".$result['output']);
            }
        }

        $this->broadcastPhase(4, 'Remote commands complete');
    }

    // --- Phase 5 ---
    /**
     * Phase 5 — health check (UPGRADE-v2 Phase 3).
     *
     * Previously this warned on every outcome and returned true unconditionally,
     * so phase 6 was unreachable (F4/F8). Now:
     *   2xx                            → healthy
     *   4xx                            → healthy, warn "health route missing"
     *   5xx / connection error (×3)    → UNHEALTHY → rollback on atomic sites
     *
     * The default path is /up (Laravel's built-in) rather than /health, which
     * almost nothing implements.
     */
    private function phase5HealthCheck(): bool
    {
        $this->log(5, 24, 'info', null, 'Phase 5: Health check');

        $appUrl = $this->site->app_url;
        if (! $appUrl) {
            $this->log(5, 24, 'warning', null, 'No app_url configured — skipping HTTP health check');
            $this->analyseRemoteLogs();
            $this->broadcastPhase(5, 'Health check skipped');

            return true;
        }

        $path = $this->site->health_path ?: config('autopilot.health.endpoint', '/up');
        $endpoint = rtrim($appUrl, '/').'/'.ltrim($path, '/');

        $attempts = (int) config('autopilot.health.retries', 3);
        $gap = (int) config('autopilot.health.retry_seconds', 5);
        $verify = $this->site->health_verify_ssl ?? true;

        $healthy = false;
        $lastReason = '';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $request = Http::timeout(config('autopilot.health.timeout', 10));

                // Verification is ON by default now. A site with a genuinely
                // broken cert opts out per-site rather than globally.
                if (! $verify) {
                    $request = $request->withoutVerifying();
                }

                $status = $request->get($endpoint)->status();

                if ($status >= 200 && $status < 400) {
                    $this->log(5, 24, 'success', "GET {$endpoint}", "HTTP {$status} (attempt {$attempt})");
                    $healthy = true;
                    break;
                }

                if ($status >= 400 && $status < 500) {
                    // The app answered, so it is up — the health route just
                    // isn't there. Not a deploy failure.
                    $this->log(5, 24, 'warning', "GET {$endpoint}",
                        "HTTP {$status} — health route missing. The app responded, so it is "
                        ."reachable; treating as healthy. Add a {$path} route (Laravel ships /up) "
                        .'for a real check.');
                    $healthy = true;
                    break;
                }

                $lastReason = "HTTP {$status}";
            } catch (\Throwable $e) {
                $lastReason = $e->getMessage();
            }

            $this->log(5, 24, 'warning', "GET {$endpoint}",
                "Attempt {$attempt}/{$attempts} failed: {$lastReason}");

            if ($attempt < $attempts) {
                sleep($gap);
            }
        }

        if (! $healthy) {
            $this->log(5, 24, 'error', "GET {$endpoint}", sprintf(
                'Unhealthy after %d attempts (%s). %s',
                $attempts,
                $lastReason,
                $this->site->isAtomic()
                    ? 'Rolling back to the previous release.'
                    : 'This site is in_place, so there is nothing to roll back to — '
                        .'inspect it manually.',
            ));
        }

        $this->analyseRemoteLogs();
        $this->broadcastPhase(5, $healthy ? 'Health check passed' : 'Health check failed');

        // in_place sites have no previous release; rolling "back" would mean
        // restoring a snapshot that does not exist, so only atomic sites fail
        // the deploy here.
        return $healthy || ! $this->site->isAtomic();
    }

    /**
     * Steps 25–26: pull the remote log and let Claude triage it.
     *
     * Never fails the deploy on its own — an AI opinion is not grounds for an
     * automatic rollback. A `critical` verdict marks the deployment as needing
     * attention instead.
     */
    private function analyseRemoteLogs(): void
    {
        $logOutput = $this->getRemoteErrorLog();
        $this->log(5, 25, 'info', null, 'Last 50 lines of Laravel log retrieved');

        if (! $logOutput) {
            return;
        }

        try {
            $analysis = $this->claude->analysePostDeployLogs($logOutput);
            $aiStatus = $analysis['status'] ?? 'ok';
            $logStatus = $aiStatus === 'critical' ? 'error' : ($aiStatus === 'warning' ? 'warning' : 'success');

            $this->log(5, 26, $logStatus, null, 'AI log analysis: '.json_encode($analysis), null, $analysis);

            if ($aiStatus === 'critical') {
                $this->deployment->forceFill(['needs_attention' => true])->save();
                $this->log(5, 26, 'warning', null,
                    'AI flagged critical issues in the logs. The deploy is NOT rolled back on an '
                    .'AI verdict alone — the deployment is marked as needing attention.');
            }
        } catch (\Throwable $e) {
            $this->log(5, 26, 'warning', null, 'AI analysis failed: '.$e->getMessage());
        }
    }

    // --- Phase 6 ---
    private function phase6Rollback(string $errorLog = ''): void
    {
        $this->log(6, 28, 'warning', null, 'Phase 6: Initiating rollback');
        // Hand our already-connected SSH to the rollback service so it doesn't
        // try to reuse a different (disconnected) DI-resolved instance.
        $this->rollback->setSsh($this->ssh);
        $this->rollback->rollback($this->deployment, $errorLog);
        $this->broadcastPhase(6, 'Rollback complete');
    }

    private function pushLocalDatabase(): void
    {
        $sourcePath = $this->getSourcePath();
        if (! $sourcePath || ! is_dir($sourcePath)) {
            $this->log(4, 162, 'warning', null, 'with_database=true but no local source_path — skipping DB push.');

            return;
        }

        $this->log(4, 162, 'info', null, 'Dumping local database and pushing to server…');

        try {
            $sync = app(DatabaseSyncService::class);
            $result = $sync->pushLocalToRemote($this->ssh, $this->site, $sourcePath, $this->deployment->id);
            $this->log(4, 162, 'success', null,
                "Database pushed: {$result['sizeMb']} MB dump imported on server.");
        } catch (\Throwable $e) {
            // DB push is invasive; fail loud so the user knows it didn't apply.
            $this->log(4, 162, 'error', null, 'Database push failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Phase 4: run the pre-deploy config audit and decide whether to proceed.
     *
     * Returns true when the deploy should stop and wait for a human. An
     * already-approved deployment skips the gate, so Approve simply re-queues
     * the same record.
     *
     * Only env KEY NAMES are compared — never values. The audit is advisory, so
     * any failure inside it is logged and the deploy continues.
     */
    private function auditRequiresApproval(): bool
    {
        if ($this->deployment->approved_at) {
            $this->log(1, 162, 'info', null, 'Pre-approved by a user — skipping risk audit.');

            return false;
        }

        try {
            $localKeys = $this->envKeys((string) $this->site->env_content);
            $remoteKeys = $this->envKeys($this->readRemoteEnv());

            $diff = [];
            foreach (array_diff($localKeys, $remoteKeys) as $key) {
                $diff[] = "+ {$key} (new in this deploy)";
            }
            foreach (array_diff($remoteKeys, $localKeys) as $key) {
                $diff[] = "- {$key} (present on server, missing locally)";
            }

            // R4: where the pending migrations come from depends on the mode.
            //
            // Atomic — the release is already prepared on the server, so ask it
            // directly; that is the code about to run.
            //
            // in_place — the audit runs before upload, so the server still has
            // the OLD code and cannot report the new migrations. Diff the local
            // files against what the server says it has already run.
            //
            // Mode A (no local source) falls through to an empty list, leaving
            // the env-key half of the audit intact.
            $migrations = $this->site->isAtomic() && $this->releasePath
                ? $this->pendingMigrations($this->releasePath)
                : $this->pendingMigrationsFromLocal();

            if ($diff === [] && $migrations === []) {
                $this->log(1, 162, 'success', null, 'Risk audit: no env key changes and no pending migrations.');

                return false;
            }

            $audit = $this->claude->auditConfig(implode("\n", $diff) ?: '(no env key changes)', $migrations);
            $risk = strtolower((string) ($audit['risk_level'] ?? 'unknown'));

            $this->deployment->forceFill([
                'ai_risk_level' => in_array($risk, ['low', 'medium', 'high'], true) ? $risk : null,
                'ai_audit_result' => $audit,
            ])->save();

            $gate = (array) config('autopilot.claude.approval_required_levels', ['high']);

            if (! in_array($risk, $gate, true)) {
                $this->log(1, 162, 'success', null, 'Risk audit: '.$risk.'. '.json_encode($audit));

                return false;
            }

            $this->deployment->forceFill(['status' => 'awaiting_approval'])->save();

            $this->log(1, 162, 'warning', null,
                'Risk audit returned HIGH — deployment held for approval. '
                .'Nothing has been uploaded or changed on the server. '
                .json_encode($audit));

            return true;
        } catch (\Throwable $e) {
            // Advisory only: never block a deploy because the audit broke.
            $this->log(1, 162, 'warning', null, 'Risk audit skipped: '.$e->getMessage());

            return false;
        }
    }

    /** Key names only — values never leave the machine. */
    private function envKeys(string $env): array
    {
        $keys = [];

        foreach (explode("\n", $env) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            $keys[] = trim(strstr($line, '=', true));
        }

        return array_values(array_unique(array_filter($keys)));
    }

    private function readRemoteEnv(): string
    {
        $path = escapeshellarg($this->statePath().'/.env');

        return $this->ssh->exec("cat {$path} 2>/dev/null || true")['output'] ?? '';
    }

    /**
     * Pending migrations as reported by the code already on the server. On a
     * first deploy there is nothing to ask, so this is best-effort.
     */
    /**
     * Pending migrations for an in_place deploy.
     *
     * R4: the audit runs BEFORE the upload here, so the server still has the
     * old code and `migrate:status --pending` there cannot see the migrations
     * this deploy adds. Diff the local migration filenames against the ones the
     * server reports as already Ran instead.
     */
    private function pendingMigrationsFromLocal(): array
    {
        $source = $this->getSourcePath();

        if (! $source || ! is_dir($source.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations')) {
            return [];
        }

        $dir = $source.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
        $localFiles = glob($dir.DIRECTORY_SEPARATOR.'*.php') ?: [];

        if ($localFiles === []) {
            return [];
        }

        $ran = $this->ranMigrationsOnServer();

        $pending = [];
        foreach ($localFiles as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $ran, true)) {
                continue;
            }

            // Same 4 KB cap as the remote path — enough to see what up() does
            // without posting an entire large migration.
            $body = trim((string) file_get_contents($file, false, null, 0, 4096));

            $pending[] = $body === '' ? $name : $name."\n".$body;
        }

        return $pending;
    }

    /**
     * Migration names the server reports as already Ran. Empty (with a warning)
     * if the status call fails — a first deploy against an empty database is
     * the normal case for that.
     */
    private function ranMigrationsOnServer(): array
    {
        $php = escapeshellarg($this->site->php_binary ?: 'php');
        $path = escapeshellarg($this->site->deploy_path);

        $result = $this->ssh->exec("cd {$path} && {$php} artisan migrate:status 2>&1");

        if (($result['exit_code'] ?? 1) !== 0) {
            $this->log(1, 162, 'warning', null,
                'Could not read migration status from the server — treating every local '
                .'migration as pending for the risk audit. '
                .$this->filterNoise($result['output'] ?? ''));

            return [];
        }

        $ran = [];
        foreach (explode("\n", $result['output'] ?? '') as $line) {
            // Only "Ran" rows; "Pending" ones are still pending.
            if (! preg_match('/\bRan\b/i', $line)) {
                continue;
            }

            if (preg_match('/(\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+)/', $line, $m)) {
                $ran[] = $m[1];
            }
        }

        return array_values(array_unique($ran));
    }

    /**
     * Migrations this deploy will run, as "name" plus the body of up().
     *
     * R4: the caller passes the path explicitly. It used to query `current`,
     * i.e. the code already live, so the migrations the deploy was ADDING were
     * invisible and the high-risk gate could essentially never fire.
     *
     * Filenames alone can't tell the model whether a migration is destructive,
     * so each one's source is included, truncated to 4 KB.
     */
    private function pendingMigrations(string $path): array
    {
        $php = escapeshellarg($this->site->php_binary ?: 'php');
        $quoted = escapeshellarg($path);

        $result = $this->ssh->exec("cd {$quoted} && {$php} artisan migrate:status --pending 2>&1");

        if (($result['exit_code'] ?? 1) !== 0) {
            // Common and benign on a first deploy (no migrations table yet).
            $this->log(1, 162, 'warning', null,
                'Could not list pending migrations — the risk audit will cover env keys only. '
                .$this->filterNoise($result['output'] ?? ''));

            return [];
        }

        $names = [];
        foreach (explode("\n", $result['output'] ?? '') as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '+') || ! str_contains($line, '_')) {
                continue;
            }

            // Rows look like "| 2026_10_04_000001_add_foo | Pending |" or
            // "2026_10_04_000001_add_foo .... Pending".
            if (preg_match('/(\d{4}_\d{2}_\d{2}_\d{6}_[A-Za-z0-9_]+)/', $line, $m)) {
                $names[] = $m[1];
            }
        }

        $names = array_values(array_unique($names));

        return array_map(fn ($name) => $this->describeMigration($path, $name), $names);
    }

    /**
     * Read a migration's source so the audit can judge what it actually does.
     */
    private function describeMigration(string $path, string $name): string
    {
        $file = escapeshellarg(rtrim($path, '/').'/database/migrations/'.$name.'.php');
        $result = $this->ssh->exec("head -c 4096 {$file} 2>/dev/null || true");
        $body = trim($result['output'] ?? '');

        return $body === ''
            ? $name.' (source unavailable)'
            : $name."\n".$body;
    }

    /**
     * Ask Claude what a failed step means and store it on the log row.
     *
     * Advisory and best-effort — a diagnosis failure must never replace the
     * real error the user needs to see.
     */
    private function diagnoseFailedStep(int $phase, int $step, ?string $command, string $output): void
    {
        if (trim($output) === '') {
            return;
        }

        try {
            $diagnosis = $this->claude->diagnoseError($output, (string) $command);

            $this->log($phase, $step, 'info', null,
                'AI diagnosis: '.json_encode($diagnosis), null, $diagnosis);
        } catch (\Throwable $e) {
            $this->log($phase, $step, 'info', null, 'AI diagnosis unavailable: '.$e->getMessage());
        }
    }

    /**
     * Step 22 — tell queue workers to pick up the new code.
     *
     * R2: on atomic sites this MUST run after the switch and against
     * `current`, not against a release path. Workers restarted before the
     * switch come back on the old release; workers started from a release path
     * stay pinned to that release forever.
     */
    private function restartQueues(string $path): void
    {
        $php = escapeshellarg($this->site->php_binary ?: 'php');
        $cmd = 'cd '.escapeshellarg($path)." && {$php} artisan queue:restart 2>&1";

        $result = $this->ssh->exec($cmd);
        $exit = $result['exit_code'] ?? 1;

        $this->log(4, 22, $exit === 0 ? 'success' : 'warning', $cmd,
            $this->filterNoise($result['output'] ?? ''), $exit);
    }

    private function atomic(): AtomicReleaseService
    {
        return $this->atomic ??= new AtomicReleaseService($this->ssh);
    }

    /**
     * Phase 2 step 1: create the release directory and remember the current one.
     */
    private function beginAtomicRelease(): void
    {
        $atomic = $this->atomic();

        $atomic->ensureLayout($this->site);

        $previous = $atomic->currentRelease($this->site);
        $this->releasePath = $this->site->releasePath($this->deployment->id);

        $this->ssh->exec('mkdir -p '.escapeshellarg($this->releasePath));

        $this->deployment->forceFill([
            'release_path' => $this->releasePath,
            'previous_release' => $previous,
        ])->save();

        $this->log(2, 27, 'info', null, sprintf(
            'Atomic release %s prepared. Live site still serving %s.',
            basename($this->releasePath),
            $previous ? basename($previous) : '(none — first deploy)',
        ));
    }

    /**
     * Phase 2 steps 6–7: flip `current` to the new release, then reload PHP-FPM.
     */
    private function completeAtomicRelease(): void
    {
        $atomic = $this->atomic();

        $atomic->switchTo($this->site, $this->releasePath);
        $this->log(2, 28, 'success', null, sprintf(
            'Switched current → %s (atomic symlink swap).', basename($this->releasePath)
        ));

        $reload = $atomic->reload($this->site);

        if ($reload['skipped']) {
            $this->log(2, 28, 'warning', null, $reload['output']);
        } else {
            $this->log(2, 28, ($reload['exit_code'] ?? 1) === 0 ? 'success' : 'warning',
                $this->site->reload_command, $reload['output'], $reload['exit_code']);
        }
    }

    /**
     * Make it unambiguous in the log whether a failed atomic deploy affected
     * the live site. Before the switch it cannot have.
     */
    private function reportAtomicFailureState(): void
    {
        if (! $this->site->isAtomic() || ! $this->releasePath) {
            return;
        }

        $live = $this->atomic()->currentRelease($this->site);

        if ($live !== $this->releasePath) {
            $this->log(2, 27, 'info', null,
                'Deploy failed before the atomic switch — the live site is unchanged and still '
                .'serving '.($live ? basename($live) : 'its previous release').'. '
                .'The failed release was left in place for inspection.');
        }
    }

    /**
     * Bring the site out of maintenance after a failed deploy.
     *
     * Best-effort by design: it runs from run()'s catch block, where the
     * original exception is the one the user needs to see. If the SSH channel
     * is already dead we log that and let the real error propagate rather than
     * masking it with a connection failure.
     */
    private function liftMaintenance(): void
    {
        if (! $this->maintenanceOn) {
            return;
        }

        $this->maintenanceOn = false;

        try {
            // The deploy failed somewhere mid-flight, so the channel may be in a
            // bad state — reconnect before spending our one attempt on `up`.
            $this->ssh->reconnect();

            $php = $this->site->php_binary ?: 'php';
            $cmd = "cd {$this->targetPath()} && {$php} artisan up 2>&1";

            $result = $this->ssh->exec($cmd);
            $exit = $result['exit_code'] ?? 1;

            $this->log(4, 23, $exit === 0 ? 'success' : 'error', $cmd,
                ($exit === 0 ? 'Site taken out of maintenance after failed deploy. ' : '')
                .($result['output'] ?? ''), $exit);
        } catch (\Throwable $e) {
            $this->log(4, 23, 'error', null,
                'Could not lift maintenance mode after failed deploy — the site is '
                .'still showing the 503 page. Run "artisan up" on the server manually. '
                .'Reason: '.$e->getMessage());
        }
    }

    private function getRemoteErrorLog(): string
    {
        $path = escapeshellarg($this->statePath().'/storage/logs/laravel.log');
        $result = $this->ssh->exec("tail -50 {$path} 2>/dev/null");

        return $result['output'] ?? '';
    }

    private function log(int $phase, int $step, string $status, ?string $command, ?string $output, ?int $exitCode = null, ?array $aiDiagnosis = null): void
    {
        if ($output !== null) {
            $output = preg_replace('/\x1B\[[0-9;]*[mGKHF]/u', '', $output);
        }
        DeployLog::record($this->deployment->id, $phase, $step, $status, $command, $output, $exitCode, $aiDiagnosis);
    }

    private function broadcastPhase(int $phase, string $message): void
    {
        event(new DeploymentPhaseCompleted($this->deployment, $phase, $message));
    }
}
