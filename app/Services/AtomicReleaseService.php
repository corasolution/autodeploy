<?php

namespace App\Services;

use App\Models\Deployment;
use App\Models\Site;
use RuntimeException;

/**
 * UPGRADE-v2 Phase 2 — atomic releases.
 *
 * Server layout:
 *
 *   {deploy_path}/
 *     releases/{deployment_id}/   one directory per deploy
 *     shared/.env
 *     shared/storage/             uploads, logs, sessions survive deploys
 *     shared/backups/
 *     current -> releases/{id}    web root = {deploy_path}/current/public
 *
 * The point is that nothing touches `current` until the new release is fully
 * built and migrated. The switch is a single `mv -Tf` of a symlink, which is
 * atomic on Linux, so a request either sees the whole old release or the whole
 * new one — never a half-extracted mix, which is what the in_place pipeline
 * does today (F5).
 *
 * Rollback is the same symlink swap in reverse, so it no longer depends on the
 * snapshot/rsync machinery that never actually ran (F4).
 */
class AtomicReleaseService
{
    public function __construct(private SshService $ssh) {}

    /**
     * Resolve what `current` points at right now, so a failed deploy can be
     * swapped back to it. Null on a first deploy (no `current` yet).
     */
    public function currentRelease(Site $site): ?string
    {
        $path = escapeshellarg($site->currentPath());
        $result = $this->ssh->exec("readlink -f {$path} 2>/dev/null || true");
        $target = trim($result['output'] ?? '');

        return $target !== '' ? $target : null;
    }

    /**
     * Create releases/, shared/ and the shared subdirectories if missing.
     * Safe to run on every deploy.
     */
    public function ensureLayout(Site $site): void
    {
        $base = rtrim($site->deploy_path, '/');
        $shared = $site->sharedPath();

        $this->ssh->exec(sprintf(
            'mkdir -p %s %s %s %s',
            escapeshellarg($base.'/releases'),
            escapeshellarg($shared),
            escapeshellarg($shared.'/storage'),
            escapeshellarg($shared.'/backups'),
        ));

        // storage/ needs the full Laravel skeleton or the first boot fails on a
        // missing framework/views directory.
        foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $dir) {
            $this->ssh->exec('mkdir -p '.escapeshellarg($shared.'/storage/'.$dir));
        }
    }

    /**
     * Point the new release at the shared .env and storage directory.
     *
     * The release's own storage/ is removed first — it ships in the zip but
     * must not win over the shared one, or uploads and logs vanish on the next
     * deploy.
     */
    public function linkShared(Site $site, string $releasePath): void
    {
        $shared = $site->sharedPath();

        $this->ssh->exec(sprintf(
            'rm -rf %s && ln -sfn %s %s',
            escapeshellarg($releasePath.'/storage'),
            escapeshellarg($shared.'/storage'),
            escapeshellarg($releasePath.'/storage'),
        ));

        $this->ssh->exec(sprintf(
            'ln -sfn %s %s',
            escapeshellarg($shared.'/.env'),
            escapeshellarg($releasePath.'/.env'),
        ));
    }

    /**
     * Flip `current` to the new release.
     *
     * `ln -sfn` on an existing symlink-to-directory creates a link *inside* the
     * target instead of replacing it, so we build a temp link and `mv -Tf` it
     * over the old one. mv on the same filesystem is a rename(2) — atomic.
     */
    public function switchTo(Site $site, string $releasePath): void
    {
        $current = $site->currentPath();
        $tmp = $current.'.tmp';

        $cmd = sprintf(
            'ln -sfn %s %s && mv -Tf %s %s',
            escapeshellarg($releasePath),
            escapeshellarg($tmp),
            escapeshellarg($tmp),
            escapeshellarg($current),
        );

        $result = $this->ssh->exec($cmd.' 2>&1');

        if (($result['exit_code'] ?? 1) !== 0) {
            throw new RuntimeException(
                'Atomic switch failed — the live site still points at the previous '
                .'release. Output: '.trim($result['output'] ?? '')
            );
        }
    }

    /**
     * Reload PHP-FPM so opcache picks up the new realpath.
     *
     * Without this the switch is invisible: opcache keys compiled files by
     * resolved path and keeps serving the old release until the TTL expires.
     */
    public function reload(Site $site): array
    {
        $command = trim((string) $site->reload_command);

        if ($command === '') {
            return ['skipped' => true, 'output' => 'No reload_command set on the site — opcache may serve the previous release until it expires.'];
        }

        $result = $this->ssh->exec($command.' 2>&1');

        return [
            'skipped' => false,
            'output' => trim($result['output'] ?? ''),
            'exit_code' => $result['exit_code'] ?? 1,
        ];
    }

    /**
     * Delete old releases, keeping the newest `keep_releases`.
     *
     * `current` and the previous release are never removed regardless of age —
     * the previous one is the rollback target.
     */
    public function prune(Site $site, Deployment $deployment): string
    {
        $keep = max(1, (int) ($site->keep_releases ?: 5));
        $base = rtrim($site->deploy_path, '/').'/releases';
        $protect = array_filter([
            $deployment->release_path,
            $deployment->previous_release,
            $this->currentRelease($site),
        ]);

        // Newest-first by mtime; everything past the keep count is a candidate.
        $listing = $this->ssh->exec('ls -1dt '.escapeshellarg($base).'/*/ 2>/dev/null || true');
        $dirs = array_filter(array_map(fn ($l) => rtrim(trim($l), '/'), explode("\n", $listing['output'] ?? '')));

        $removed = [];
        foreach (array_slice($dirs, $keep) as $dir) {
            if ($dir === '' || in_array($dir, $protect, true)) {
                continue;
            }

            // Belt and braces: never let a malformed listing turn into rm -rf /.
            if (! str_starts_with($dir, $base.'/')) {
                continue;
            }

            $this->ssh->exec('rm -rf '.escapeshellarg($dir));
            $removed[] = basename($dir);
        }

        return $removed === []
            ? "Kept {$keep} releases; nothing to prune."
            : 'Pruned '.count($removed).' release(s): '.implode(', ', $removed);
    }
}
