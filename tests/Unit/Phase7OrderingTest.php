<?php

namespace Tests\Unit;

use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\ClaudeAgentService;
use App\Services\DeployService;
use App\Services\RollbackService;
use App\Services\SshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 7 acceptance tests (R2, R3, R5).
 *
 * These assert on the ORDER and PRESENCE of remote commands, because that is
 * exactly what the three bugs were about: the right commands running at the
 * wrong moment, or a failure never surfacing at all.
 */
class Phase7OrderingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSsh(array $responses = []): SshService
    {
        return new class($responses) extends SshService
        {
            public array $executed = [];

            public function __construct(private array $responses = []) {}

            public function connect(): void {}

            public function reconnect(): void {}

            public function disconnect(): void {}

            public function uploadContent(string $content, string $remotePath): void {}

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

    private function makeDeployment(array $siteAttrs = []): Deployment
    {
        $user = User::factory()->create();

        $server = Server::create([
            'user_id' => $user->id, 'name' => 'p7-server', 'panel_type' => 'aapanel',
            'host' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root',
            'ssh_auth' => 'password', 'active' => true, 'web_user' => 'www',
        ]);

        $site = Site::create(array_merge([
            'server_id' => $server->id, 'name' => 'p7-site',
            'deploy_path' => '/www/wwwroot/p7', 'branch' => 'main', 'php_binary' => 'php',
            'active' => true, 'env_content' => "APP_NAME=Test\nDB_CONNECTION=mysql\n",
        ], $siteAttrs));

        return Deployment::create([
            'user_id' => $user->id, 'server_id' => $server->id, 'site_id' => $site->id,
            'branch' => 'main', 'status' => 'running',
        ]);
    }

    private function service(Deployment $deployment, SshService $ssh, ?string $releasePath = null): DeployService
    {
        $service = new DeployService(
            $this->createMock(ClaudeAgentService::class),
            $this->createMock(RollbackService::class),
        );

        foreach ([
            'deployment' => $deployment,
            'site' => $deployment->site,
            'server' => $deployment->server,
            'ssh' => $ssh,
            'releasePath' => $releasePath,
        ] as $prop => $value) {
            (new \ReflectionProperty($service, $prop))->setValue($service, $value);
        }

        return $service;
    }

    private function invokePrivate(DeployService $service, string $method, array $args = []): mixed
    {
        return (new \ReflectionMethod($service, $method))->invokeArgs($service, $args);
    }

    private function indexOf(array $commands, string $needle): ?int
    {
        foreach ($commands as $i => $cmd) {
            if (str_contains($cmd, $needle)) {
                return $i;
            }
        }

        return null;
    }

    // ── R2 ───────────────────────────────────────────────────────────────────

    /** On an atomic site, phase 4 must NOT restart queues — the switch hasn't happened. */
    public function test_atomic_phase4_does_not_restart_queues(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/'.$deployment->id);

        $this->invokePrivate($service, 'phase4RemoteCommands');
        $this->invokePrivate($service, 'phase4Migrate');

        $this->assertNull(
            $this->indexOf($ssh->executed, 'queue:restart'),
            'R2: queue:restart inside phase 4 would restart workers onto the OLD release'
        );
    }

    /** in_place is unchanged: the new code IS the live code by phase 4. */
    public function test_in_place_still_restarts_queues_in_phase4(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'in_place']);
        $service = $this->service($deployment, $ssh);

        $this->invokePrivate($service, 'phase4RemoteCommands');
        $this->invokePrivate($service, 'phase4Migrate');

        $this->assertNotNull($this->indexOf($ssh->executed, 'queue:restart'));
    }

    public function test_restart_queues_targets_current_not_a_release_path(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/9');

        $this->invokePrivate($service, 'restartQueues', [$deployment->site->currentPath()]);

        $cmd = $ssh->executed[0];

        $this->assertStringContainsString('/www/wwwroot/p7/current', $cmd);
        $this->assertStringNotContainsString('/releases/', $cmd,
            'Workers started from a release path stay pinned to that release forever');
    }

    // ── R3 ───────────────────────────────────────────────────────────────────

    /** The headline bug: a failed composer must stop the deploy, not sail through. */
    public function test_composer_failure_aborts_before_any_switch(): void
    {
        $ssh = $this->fakeSsh([
            'composer install' => ['output' => 'Could not resolve dependencies', 'exit_code' => 1],
        ]);

        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/'.$deployment->id);

        try {
            $this->invokePrivate($service, 'phase4RemoteCommands');
            $this->fail('composer install failing twice must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('composer install failed', $e->getMessage());
        }

        $this->assertNull($this->indexOf($ssh->executed, 'mv -Tf'),
            'The live site must never be switched to a release with a broken vendor/');
        $this->assertNull($this->indexOf($ssh->executed, 'migrate --force'));
    }

    public function test_composer_command_has_no_exit_code_swallowing_pipeline(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $composer = $ssh->executed[$this->indexOf($ssh->executed, 'composer install')];

        $this->assertStringNotContainsString('|| true', $composer);
        $this->assertStringNotContainsString('grep -v', $composer,
            'Filtering in the shell replaces composer\'s exit code with grep\'s');
    }

    /** Noise filtering moved into PHP — it must still filter, just not in the shell. */
    public function test_deprecation_noise_is_filtered_in_php(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment();
        $service = $this->service($deployment, $ssh);

        $filtered = $this->invokePrivate($service, 'filterNoise', [
            "Deprecated: thing is deprecated\nInstalling dependencies\nPHP Warning: something\nDone",
        ]);

        $this->assertStringNotContainsString('Deprecated:', $filtered);
        $this->assertStringNotContainsString('PHP Warning', $filtered);
        $this->assertStringContainsString('Installing dependencies', $filtered);
        $this->assertStringContainsString('Done', $filtered);
    }

    public function test_atomic_release_boot_check_runs_before_migrate(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $this->assertNotNull($this->indexOf($ssh->executed, 'vendor/autoload.php'),
            'A release that cannot boot must be caught before migrate or the switch');
    }

    public function test_release_that_cannot_boot_aborts(): void
    {
        $ssh = $this->fakeSsh([
            'vendor/autoload.php' => ['output' => 'no autoloader', 'exit_code' => 1],
        ]);

        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Release cannot boot');

        $this->invokePrivate($service, 'phase4RemoteCommands');
    }

    // ── R5 ───────────────────────────────────────────────────────────────────

    /** optimize:clear includes cache:clear, and atomic shares storage with the live release. */
    public function test_atomic_deploy_never_clears_the_live_cache(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $this->assertNull($this->indexOf($ssh->executed, 'optimize:clear'));
        $this->assertNull($this->indexOf($ssh->executed, 'cache:clear'));

        // The targeted equivalents must still run.
        $this->assertNotNull($this->indexOf($ssh->executed, 'config:clear'));
        $this->assertNotNull($this->indexOf($ssh->executed, 'view:clear'));
    }

    public function test_in_place_keeps_optimize_clear(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'in_place']);
        $service = $this->service($deployment, $ssh);

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $this->assertNotNull($this->indexOf($ssh->executed, 'optimize:clear'));
    }

    // ── R4 ───────────────────────────────────────────────────────────────────

    /** The audit must read the RELEASE, not `current` (which is the old code). */
    public function test_pending_migrations_reads_the_given_path(): void
    {
        $ssh = $this->fakeSsh([
            'migrate:status' => ['output' => "| 2026_10_04_000009_drop_old_column | Pending |\n", 'exit_code' => 0],
            'head -c 4096' => ['output' => '<?php // $table->dropColumn(["legacy"]);', 'exit_code' => 0],
        ]);

        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/5');

        $migrations = $this->invokePrivate($service, 'pendingMigrations', ['/www/wwwroot/p7/releases/5']);

        $statusCmd = $ssh->executed[$this->indexOf($ssh->executed, 'migrate:status')];
        $this->assertStringContainsString('/releases/5', $statusCmd);
        $this->assertStringNotContainsString('/current', $statusCmd);

        // The body must come through, or the model can't judge destructiveness.
        $this->assertCount(1, $migrations);
        $this->assertStringContainsString('2026_10_04_000009_drop_old_column', $migrations[0]);
        $this->assertStringContainsString('dropColumn', $migrations[0]);
    }

    public function test_failed_migrate_status_returns_empty_without_throwing(): void
    {
        $ssh = $this->fakeSsh([
            'migrate:status' => ['output' => 'SQLSTATE[42S02]: no migrations table', 'exit_code' => 1],
        ]);

        $deployment = $this->makeDeployment(['release_mode' => 'atomic']);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->assertSame([], $this->invokePrivate($service, 'pendingMigrations', ['/www/wwwroot/p7/releases/1']));
    }
    // ── Phase 7 follow-up: maintenance window vs the audit gate ──────────────

    /**
     * An atomic site with maintenance_on_migrate must NOT go down in phase 4a.
     *
     * The risk audit runs between 4a and 4b and returns early when it holds a
     * deploy — a path that never reaches `artisan up`. Taking the site down
     * before that gate left it stranded on the 503 page until someone approved.
     */
    public function test_atomic_maintenance_site_held_by_audit_is_never_taken_down(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment([
            'release_mode' => 'atomic',
            'maintenance_on_migrate' => true,
        ]);

        $claude = $this->createMock(ClaudeAgentService::class);
        $claude->method('auditConfig')->willReturn([
            'risk_level' => 'high',
            'warnings' => ['drops a column'],
            'suggestions' => [],
        ]);

        $service = new DeployService($claude, $this->createMock(RollbackService::class));
        foreach ([
            'deployment' => $deployment,
            'site' => $deployment->site,
            'server' => $deployment->server,
            'ssh' => $ssh,
            'releasePath' => '/www/wwwroot/p7/releases/'.$deployment->id,
        ] as $prop => $value) {
            (new \ReflectionProperty($service, $prop))->setValue($service, $value);
        }

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $held = $this->invokePrivate($service, 'auditRequiresApproval');

        $this->assertTrue($held, 'A high risk_level must hold the deploy');
        $this->assertSame('awaiting_approval', $deployment->fresh()->status);

        $this->assertNull(
            $this->indexOf($ssh->executed, 'artisan down'),
            'The live site must not be taken down before the audit gate — that path never reaches artisan up'
        );

        // And the flag that would make run()'s catch lift maintenance is still off.
        $this->assertFalse(
            (new \ReflectionProperty($service, 'maintenanceOn'))->getValue($service)
        );
    }

    /** Once past the gate, the window does open. */
    public function test_atomic_maintenance_site_goes_down_in_phase4_migrate(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment([
            'release_mode' => 'atomic',
            'maintenance_on_migrate' => true,
        ]);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->invokePrivate($service, 'phase4Migrate');

        $down = $this->indexOf($ssh->executed, 'artisan down');
        $migrate = $this->indexOf($ssh->executed, 'migrate --force');

        $this->assertNotNull($down, 'maintenance_on_migrate must still take the site down');
        $this->assertTrue($down < $migrate, 'down must precede migrate');

        // And the window closes again within the same phase: step 23 runs at the
        // end, which is also what clears the maintenanceOn flag.
        $up = $this->indexOf($ssh->executed, 'artisan up');
        $this->assertNotNull($up, 'the window must be closed before phase 4b returns');
        $this->assertTrue($migrate < $up);
        $this->assertFalse(
            (new \ReflectionProperty($service, 'maintenanceOn'))->getValue($service),
            'flag is cleared once the site is back up, so the catch has nothing to lift'
        );
    }

    /** Atomic without the flag stays up throughout — the old release serves traffic. */
    public function test_atomic_without_the_flag_never_goes_down(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment([
            'release_mode' => 'atomic',
            'maintenance_on_migrate' => false,
        ]);
        $service = $this->service($deployment, $ssh, '/www/wwwroot/p7/releases/1');

        $this->invokePrivate($service, 'phase4RemoteCommands');
        $this->invokePrivate($service, 'phase4Migrate');

        $this->assertNull($this->indexOf($ssh->executed, 'artisan down'));
    }

    /** in_place is unchanged: it still goes down inside phase 4a. */
    public function test_in_place_still_goes_down_in_phase4a(): void
    {
        $ssh = $this->fakeSsh();
        $deployment = $this->makeDeployment(['release_mode' => 'in_place']);
        $service = $this->service($deployment, $ssh);

        $this->invokePrivate($service, 'phase4RemoteCommands');

        $this->assertNotNull($this->indexOf($ssh->executed, 'artisan down'));
        $this->assertTrue((new \ReflectionProperty($service, 'maintenanceOn'))->getValue($service));
    }
}
