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
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 1 acceptance tests for F3 (site left in maintenance) and
 * F6 (failed local build reported as success).
 */
class DeployServiceSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeployment(array $siteAttrs = []): Deployment
    {
        $user = User::factory()->create();

        $server = Server::create([
            'user_id' => $user->id,
            'name' => 'unit-server',
            'panel_type' => 'aapanel',
            'host' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_auth' => 'password',
            'active' => true,
        ]);

        $site = Site::create(array_merge([
            'server_id' => $server->id,
            'name' => 'unit-site',
            'deploy_path' => '/www/wwwroot/unit',
            'branch' => 'main',
            'php_binary' => 'php',
            'active' => true,
        ], $siteAttrs));

        return Deployment::create([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'site_id' => $site->id,
            'branch' => 'main',
            'status' => 'pending',
        ]);
    }

    /**
     * Drive the private maintenance machinery directly: set the flag as phase 4
     * step 15 would, then assert the recovery path issues `artisan up`.
     */
    public function test_failed_deploy_lifts_maintenance_mode(): void
    {
        $deployment = $this->makeDeployment();

        $executed = [];

        $ssh = new class($executed) extends SshService
        {
            public array $executed;

            // Bypass SshService's constructor — no connection in a unit test.
            public function __construct(array &$executed)
            {
                $this->executed = &$executed;
            }

            public function reconnect(): void {}

            public function exec(string $command, int $timeout = 300): array
            {
                $this->executed[] = $command;

                return ['output' => '', 'exit_code' => 0];
            }
        };

        $service = new DeployService(
            $this->createMock(ClaudeAgentService::class),
            $this->createMock(RollbackService::class),
        );

        $this->setPrivate($service, 'deployment', $deployment);
        $this->setPrivate($service, 'site', $deployment->site);
        $this->setPrivate($service, 'server', $deployment->server);
        $this->setPrivate($service, 'ssh', $ssh);
        $this->setPrivate($service, 'maintenanceOn', true);

        $this->callPrivate($service, 'liftMaintenance');

        $this->assertCount(1, $executed, 'Exactly one recovery command should run');
        $this->assertStringContainsString('artisan up', $executed[0],
            'F3: a deploy that throws after `artisan down` must still bring the site back up');

        // Flag cleared, so a second call is a no-op.
        $this->callPrivate($service, 'liftMaintenance');
        $this->assertCount(1, $executed, 'liftMaintenance must be idempotent');
    }

    /**
     * F6: shell_exec() discarded the exit code, so a failing `npm run build`
     * was logged as success and shipped. runLocal() must throw instead.
     */
    public function test_failing_local_build_throws_and_is_logged_as_error(): void
    {
        $deployment = $this->makeDeployment();

        Process::fake([
            '*' => Process::result(output: '', errorOutput: 'vite: build failed', exitCode: 1),
        ]);

        $service = new DeployService(
            $this->createMock(ClaudeAgentService::class),
            $this->createMock(RollbackService::class),
        );

        $this->setPrivate($service, 'deployment', $deployment);
        $this->setPrivate($service, 'site', $deployment->site);

        try {
            $this->callPrivate($service, 'runLocal', [7, 'npm run build', 'npm run build', null]);
            $this->fail('runLocal() must throw when the command exits non-zero');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('npm run build', $e->getMessage());
            $this->assertStringContainsString('vite: build failed', $e->getMessage());
        }

        $this->assertDatabaseHas('deploy_logs', [
            'deployment_id' => $deployment->id,
            'step' => 7,
            'status' => 'error',
        ]);
    }

    public function test_successful_local_build_is_logged_as_success(): void
    {
        $deployment = $this->makeDeployment();

        Process::fake([
            '*' => Process::result(output: 'built in 3.2s', exitCode: 0),
        ]);

        $service = new DeployService(
            $this->createMock(ClaudeAgentService::class),
            $this->createMock(RollbackService::class),
        );

        $this->setPrivate($service, 'deployment', $deployment);
        $this->setPrivate($service, 'site', $deployment->site);

        $output = $this->callPrivate($service, 'runLocal', [7, 'npm run build', 'npm run build', null]);

        $this->assertStringContainsString('built in 3.2s', $output);
        $this->assertDatabaseHas('deploy_logs', [
            'deployment_id' => $deployment->id,
            'step' => 7,
            'status' => 'success',
        ]);
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }

    private function callPrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invokeArgs($object, $args);
    }
}
