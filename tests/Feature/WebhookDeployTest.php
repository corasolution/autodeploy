<?php

namespace Tests\Feature;

use App\Jobs\RunDeploymentJob;
use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 1 acceptance tests for F1 (auth bypass) and F2 (missing
 * site_id).
 */
class WebhookDeployTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_123456';

    private function makeSite(array $siteAttrs = []): Site
    {
        $user = User::factory()->create();

        $server = Server::create([
            'user_id' => $user->id,
            'name' => 'test-server',
            'panel_type' => 'aapanel',
            'host' => '203.0.113.10',
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'ssh_auth' => 'password',
            'active' => true,
        ]);

        return Site::create(array_merge([
            'server_id' => $server->id,
            'name' => 'test-site',
            'deploy_path' => '/www/wwwroot/test',
            'branch' => 'main',
            'php_binary' => 'php',
            'active' => true,
            'webhook_secret' => self::SECRET,
        ], $siteAttrs));
    }

    /** Build the body + matching signature header. */
    private function signed(array $payload, string $secret = self::SECRET): array
    {
        $body = json_encode($payload);

        return [$body, 'sha256='.hash_hmac('sha256', $body, $secret)];
    }

    private function postWebhook(string $body, ?string $signature): TestResponse
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($signature !== null) {
            $headers['X-Signature'] = $signature;
        }

        return $this->call('POST', '/api/webhooks/deploy', [], [], [], $this->transformHeaders($headers), $body);
    }

    private function transformHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.str_replace('-', '_', strtoupper($key))] = $value;
        }
        $server['CONTENT_TYPE'] = $headers['Content-Type'] ?? 'application/json';

        return $server;
    }

    /**
     * F1: the original `$server->webhook_secret !== $token` authenticated every
     * caller when no secret was configured, because null !== null is false.
     */
    public function test_webhook_with_no_secret_configured_is_rejected(): void
    {
        Queue::fake();

        $site = $this->makeSite(['webhook_secret' => null]);

        [$body] = $this->signed(['site' => $site->name, 'branch' => 'main']);

        // No secret on the site and no signature header — the exact shape that
        // used to pass.
        $this->postWebhook($body, null)->assertStatus(401);

        Queue::assertNothingPushed();
        $this->assertSame(0, Deployment::count());
    }

    public function test_webhook_with_wrong_signature_is_rejected(): void
    {
        Queue::fake();

        $site = $this->makeSite();
        [$body] = $this->signed(['site' => $site->name, 'branch' => 'main'], 'the-wrong-secret');

        $this->postWebhook($body, 'sha256='.hash_hmac('sha256', $body, 'the-wrong-secret'))
            ->assertStatus(401);

        Queue::assertNothingPushed();
        $this->assertSame(0, Deployment::count());
    }

    /**
     * F2: a valid webhook must create the Deployment WITH site_id, or
     * DeployService::run() dereferences a null site and the deploy dies.
     */
    public function test_valid_webhook_queues_deployment_with_site_id(): void
    {
        Queue::fake();

        $site = $this->makeSite();
        [$body, $signature] = $this->signed([
            'site' => $site->name,
            'branch' => 'main',
            'commit' => 'abc123',
        ]);

        $this->postWebhook($body, $signature)
            ->assertOk()
            ->assertJson(['status' => 'queued']);

        $deployment = Deployment::sole();

        $this->assertSame($site->id, $deployment->site_id, 'Deployment must carry site_id (F2)');
        $this->assertSame($site->server_id, $deployment->server_id);
        $this->assertSame('abc123', $deployment->commit_hash);
        $this->assertSame('webhook', $deployment->triggered_by);

        Queue::assertPushed(RunDeploymentJob::class);
    }

    public function test_branch_mismatch_is_rejected(): void
    {
        Queue::fake();

        $site = $this->makeSite(['branch' => 'main']);
        [$body, $signature] = $this->signed(['site' => $site->name, 'branch' => 'develop']);

        $this->postWebhook($body, $signature)->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_already_deployed_commit_is_skipped(): void
    {
        Queue::fake();

        $site = $this->makeSite();

        Deployment::create([
            'server_id' => $site->server_id,
            'site_id' => $site->id,
            'branch' => 'main',
            'commit_hash' => 'deadbeef',
            'status' => 'success',
        ]);

        [$body, $signature] = $this->signed([
            'site' => $site->name,
            'branch' => 'main',
            'commit' => 'deadbeef',
        ]);

        $this->postWebhook($body, $signature)
            ->assertOk()
            ->assertJson(['status' => 'skipped']);

        Queue::assertNothingPushed();
    }
}
