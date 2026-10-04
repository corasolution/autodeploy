<?php

namespace Tests\Unit;

use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\AtomicReleaseService;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 2 acceptance tests.
 *
 * The property that matters: nothing touches `current` until the new release is
 * fully built. These drive a fake SSH and assert on the command sequence.
 */
class AtomicReleaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSsh(array $responses = []): SshService
    {
        return new class($responses) extends SshService
        {
            public array $executed = [];

            public function __construct(private array $responses = []) {}

            public function exec(string $command, int $timeout = 300): array
            {
                $this->executed[] = $command;

                foreach ($this->responses as $needle => $response) {
                    if (str_contains($command, $needle)) {
                        return $response;
                    }
                }

                return ['output' => '', 'exit_code' => 0];
            }
        };
    }

    private function makeSite(array $attrs = []): Site
    {
        $user = User::factory()->create();

        $server = Server::create([
            'user_id' => $user->id, 'name' => 'atomic-server', 'panel_type' => 'aapanel',
            'host' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root',
            'ssh_auth' => 'password', 'active' => true,
        ]);

        return Site::create(array_merge([
            'server_id' => $server->id, 'name' => 'atomic-site',
            'deploy_path' => '/www/wwwroot/app', 'branch' => 'main',
            'php_binary' => 'php', 'active' => true,
            'release_mode' => 'atomic', 'keep_releases' => 3,
        ], $attrs));
    }

    public function test_switch_is_a_temp_link_then_atomic_rename(): void
    {
        $ssh = $this->fakeSsh();
        $site = $this->makeSite();

        (new AtomicReleaseService($ssh))->switchTo($site, '/www/wwwroot/app/releases/42');

        $this->assertCount(1, $ssh->executed, 'The switch must be a single command');

        $cmd = $ssh->executed[0];

        // ln -sfn onto an existing symlink-to-dir would nest the link inside the
        // target; the temp-link + mv -Tf dance is what makes it atomic.
        $this->assertStringContainsString('current.tmp', $cmd);
        $this->assertStringContainsString('mv -Tf', $cmd);
        $this->assertTrue(
            strpos($cmd, 'ln -sfn') < strpos($cmd, 'mv -Tf'),
            'The temp symlink must be created before the rename'
        );
    }

    public function test_failed_switch_throws_rather_than_reporting_success(): void
    {
        $ssh = $this->fakeSsh(['mv -Tf' => ['output' => 'Permission denied', 'exit_code' => 1]]);
        $site = $this->makeSite();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic switch failed');

        (new AtomicReleaseService($ssh))->switchTo($site, '/www/wwwroot/app/releases/42');
    }

    public function test_link_shared_removes_release_storage_before_linking(): void
    {
        $ssh = $this->fakeSsh();
        $site = $this->makeSite();

        (new AtomicReleaseService($ssh))->linkShared($site, '/www/wwwroot/app/releases/42');

        $storageCmd = $ssh->executed[0];

        // If the shipped storage/ isn't removed first, ln -sfn drops the link
        // INSIDE it and every upload and log is lost on the next deploy.
        $this->assertStringContainsString('rm -rf', $storageCmd);
        $this->assertTrue(
            strpos($storageCmd, 'rm -rf') < strpos($storageCmd, 'ln -sfn'),
            'The release storage/ must be removed before the shared link is made'
        );
        $this->assertStringContainsString('/shared/.env', $ssh->executed[1]);
    }

    public function test_prune_keeps_configured_count_and_never_removes_active(): void
    {
        $base = '/www/wwwroot/app/releases';

        $ssh = $this->fakeSsh([
            'ls -1dt' => ['output' => "{$base}/50/\n{$base}/49/\n{$base}/48/\n{$base}/47/\n{$base}/46/\n", 'exit_code' => 0],
            'readlink -f' => ['output' => "{$base}/50\n", 'exit_code' => 0],
        ]);

        $site = $this->makeSite(['keep_releases' => 3]);

        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id, 'branch' => 'main',
            'status' => 'success',
            'release_path' => "{$base}/50",
            'previous_release' => "{$base}/49",
        ]);

        $summary = (new AtomicReleaseService($ssh))->prune($site, $deployment);

        $removals = array_values(array_filter($ssh->executed, fn ($c) => str_starts_with($c, 'rm -rf')));

        // keep_releases=3 → 50, 49, 48 stay; 47 and 46 go.
        $this->assertCount(2, $removals, 'Exactly the releases past the keep count should go');
        $this->assertStringContainsString('47', $removals[0]);
        $this->assertStringContainsString('46', $removals[1]);

        foreach ($removals as $cmd) {
            $this->assertStringNotContainsString("{$base}/50", $cmd, 'Never prune the live release');
            $this->assertStringNotContainsString("{$base}/49", $cmd, 'Never prune the rollback target');
        }

        $this->assertStringContainsString('Pruned 2', $summary);
    }

    public function test_prune_refuses_paths_outside_the_releases_directory(): void
    {
        // A malformed listing must never turn into rm -rf on something else.
        $ssh = $this->fakeSsh([
            'ls -1dt' => ['output' => "/etc/\n/www/wwwroot/app/releases/9/\n", 'exit_code' => 0],
            'readlink -f' => ['output' => '', 'exit_code' => 0],
        ]);

        $site = $this->makeSite(['keep_releases' => 1]);
        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id,
            'branch' => 'main', 'status' => 'success',
        ]);

        (new AtomicReleaseService($ssh))->prune($site, $deployment);

        foreach ($ssh->executed as $cmd) {
            $this->assertStringNotContainsString("rm -rf '/etc'", $cmd);
        }
    }

    public function test_reload_is_skipped_with_a_warning_when_unconfigured(): void
    {
        $ssh = $this->fakeSsh();
        $site = $this->makeSite(['reload_command' => null]);

        $result = (new AtomicReleaseService($ssh))->reload($site);

        $this->assertTrue($result['skipped']);
        $this->assertStringContainsString('opcache', $result['output']);
        $this->assertSame([], $ssh->executed);
    }
}
