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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 3 acceptance tests.
 *
 * The old phase5HealthCheck() warned on every outcome and returned true
 * unconditionally, so rollback was unreachable (F4/F8).
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(Site $site, Deployment $deployment): DeployService
    {
        $ssh = new class extends SshService
        {
            public function __construct() {}

            public function exec(string $command, int $timeout = 300): array
            {
                return ['output' => '', 'exit_code' => 0];
            }
        };

        $service = new DeployService(
            $this->createMock(ClaudeAgentService::class),
            $this->createMock(RollbackService::class),
        );

        foreach (['site' => $site, 'deployment' => $deployment, 'ssh' => $ssh] as $prop => $value) {
            (new \ReflectionProperty($service, $prop))->setValue($service, $value);
        }

        return $service;
    }

    private function makeSite(array $attrs = []): Site
    {
        $user = User::factory()->create();

        $server = Server::create([
            'user_id' => $user->id, 'name' => 'health-server', 'panel_type' => 'aapanel',
            'host' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root',
            'ssh_auth' => 'password', 'active' => true,
        ]);

        return Site::create(array_merge([
            'server_id' => $server->id, 'name' => 'health-site',
            'deploy_path' => '/www/wwwroot/app', 'branch' => 'main', 'php_binary' => 'php',
            'active' => true, 'app_url' => 'https://example.test',
            'release_mode' => 'atomic',
        ], $attrs));
    }

    private function check(Site $site): bool
    {
        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id,
            'branch' => 'main', 'status' => 'running',
        ]);

        $service = $this->makeService($site, $deployment);

        return (new \ReflectionMethod($service, 'phase5HealthCheck'))->invoke($service);
    }

    public function test_2xx_is_healthy(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->assertTrue($this->check($this->makeSite()));
    }

    /** 4xx means the app answered but the route is missing — not a failure. */
    public function test_4xx_is_treated_as_healthy_with_a_warning(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);

        $site = $this->makeSite();
        $this->assertTrue($this->check($site));

        $this->assertDatabaseHas('deploy_logs', ['step' => 24, 'status' => 'warning']);
    }

    /** The regression that made rollback unreachable. */
    public function test_5xx_is_unhealthy_on_an_atomic_site(): void
    {
        config(['autopilot.health.retries' => 2, 'autopilot.health.retry_seconds' => 0]);
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->assertFalse($this->check($this->makeSite(['release_mode' => 'atomic'])));
    }

    /**
     * in_place sites have no previous release, so failing the deploy here would
     * only trigger a rollback that cannot work.
     */
    public function test_5xx_does_not_fail_an_in_place_site(): void
    {
        config(['autopilot.health.retries' => 2, 'autopilot.health.retry_seconds' => 0]);
        Http::fake(['*' => Http::response('Server Error', 500)]);

        $this->assertTrue($this->check($this->makeSite(['release_mode' => 'in_place'])));
        $this->assertDatabaseHas('deploy_logs', ['step' => 24, 'status' => 'error']);
    }

    public function test_connection_error_is_retried_then_fails(): void
    {
        config(['autopilot.health.retries' => 3, 'autopilot.health.retry_seconds' => 0]);
        Http::fake(fn () => throw new \RuntimeException('Connection refused'));

        $this->assertFalse($this->check($this->makeSite()));

        // One warning per attempt, plus the final error.
        $this->assertSame(3, Deployment::sole()->logs()
            ->where('step', 24)->where('status', 'warning')->count());
    }

    public function test_site_health_path_overrides_the_default(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->check($this->makeSite(['health_path' => '/healthz']));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/healthz'));
    }

    public function test_default_path_is_laravels_up_route(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->check($this->makeSite(['health_path' => null]));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/up'));
    }
}
