<?php

namespace Tests\Feature;

use App\Jobs\RunDeploymentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 1 acceptance test for F7: `with_database` REPLACEs rows in
 * the remote database, so on a production site it must not be one click away.
 */
class DatabasePushGuardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function makeSite(string $environment = 'production'): Site
    {
        $this->user = User::factory()->create();

        $server = Server::create([
            'user_id' => $this->user->id,
            'name' => 'guard-server',
            'panel_type' => 'aapanel',
            'host' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_auth' => 'password',
            'active' => true,
        ]);

        return Site::create([
            'server_id' => $server->id,
            'name' => 'live-shop',
            'deploy_path' => '/www/wwwroot/live',
            'branch' => 'main',
            'php_binary' => 'php',
            'active' => true,
            'environment' => $environment,
        ]);
    }

    public function test_production_db_push_without_confirmation_is_rejected(): void
    {
        Queue::fake();
        $site = $this->makeSite('production');

        $this->actingAs($this->user)
            ->post('/deploy', [
                'site_id' => $site->id,
                'with_database' => true,
            ])
            ->assertSessionHasErrors('confirm_site_name');

        Queue::assertNothingPushed();
        $this->assertSame(0, Deployment::count());
    }

    public function test_production_db_push_with_wrong_name_is_rejected(): void
    {
        Queue::fake();
        $site = $this->makeSite('production');

        $this->actingAs($this->user)
            ->post('/deploy', [
                'site_id' => $site->id,
                'with_database' => true,
                'confirm_site_name' => 'not-the-site',
            ])
            ->assertSessionHasErrors('confirm_site_name');

        Queue::assertNothingPushed();
    }

    public function test_production_db_push_with_matching_name_proceeds(): void
    {
        Queue::fake();
        $site = $this->makeSite('production');

        $this->actingAs($this->user)
            ->post('/deploy', [
                'site_id' => $site->id,
                'with_database' => true,
                'confirm_site_name' => 'live-shop',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Deployment::count());
        $this->assertTrue((bool) Deployment::sole()->with_database);
        Queue::assertPushed(RunDeploymentJob::class);
    }

    /** Non-production sites keep the old one-click behaviour. */
    public function test_staging_db_push_needs_no_confirmation(): void
    {
        Queue::fake();
        $site = $this->makeSite('staging');

        $this->actingAs($this->user)
            ->post('/deploy', [
                'site_id' => $site->id,
                'with_database' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Deployment::count());
        Queue::assertPushed(RunDeploymentJob::class);
    }

    /** A normal deploy (no DB push) is unaffected by the guard. */
    public function test_plain_deploy_is_unaffected(): void
    {
        Queue::fake();
        $site = $this->makeSite('production');

        $this->actingAs($this->user)
            ->post('/deploy', ['site_id' => $site->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Deployment::count());
        $this->assertFalse((bool) Deployment::sole()->with_database);
    }
}
