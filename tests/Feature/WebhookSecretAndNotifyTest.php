<?php

namespace Tests\Feature;

use App\Models\Deployment;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\DeployNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** UPGRADE-v2 Phase 5 acceptance tests. */
class WebhookSecretAndNotifyTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function makeSite(array $attrs = []): Site
    {
        $this->user = User::factory()->create();

        $server = Server::create(array_merge([
            'user_id' => $this->user->id, 'name' => 'ci-server', 'panel_type' => 'aapanel',
            'host' => '203.0.113.10', 'ssh_port' => 22, 'ssh_user' => 'root',
            'ssh_auth' => 'password', 'active' => true,
        ], $attrs['server'] ?? []));

        return Site::create(array_merge([
            'server_id' => $server->id, 'name' => 'ci-site',
            'deploy_path' => '/www/wwwroot/ci', 'branch' => 'main',
            'php_binary' => 'php', 'active' => true,
        ], $attrs['site'] ?? []));
    }

    public function test_rotating_the_secret_returns_it_once_and_stores_it_encrypted(): void
    {
        $site = $this->makeSite();

        $response = $this->actingAs($this->user)
            ->postJson("/sites/{$site->id}/webhook-secret")
            ->assertOk();

        $secret = $response->json('secret');

        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertSame($secret, $site->fresh()->webhook_secret, 'Decrypts back to the same value');

        // Stored ciphertext must not be the plaintext.
        $stored = \DB::table('sites')->where('id', $site->id)->value('webhook_secret');
        $this->assertNotSame($secret, $stored, 'Secret must be encrypted at rest');
    }

    /** The signature only verifies if the workflow signs the exact bytes posted. */
    public function test_generated_workflow_signs_the_body_without_a_trailing_newline(): void
    {
        $site = $this->makeSite(['site' => ['branch' => 'release']]);

        $workflow = $this->actingAs($this->user)
            ->postJson("/sites/{$site->id}/webhook-secret")
            ->json('workflow');

        // `echo` would append \n and break the HMAC.
        $this->assertStringContainsString("printf '%s'", $workflow);
        $this->assertStringNotContainsString('echo "$BODY"', $workflow);
        $this->assertStringContainsString('X-Signature: sha256=', $workflow);
        $this->assertStringContainsString('branches: [release]', $workflow);
    }

    public function test_secret_is_not_exposed_on_the_edit_page(): void
    {
        $site = $this->makeSite();
        $this->actingAs($this->user)->postJson("/sites/{$site->id}/webhook-secret");

        // $hidden on the model — it must never ride along in a page payload.
        $this->assertArrayNotHasKey('webhook_secret', $site->fresh()->toArray());
    }

    public function test_notifier_posts_a_success_message_to_telegram(): void
    {
        config(['autopilot.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $site = $this->makeSite(['server' => ['telegram_chat_id' => '12345']]);

        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id, 'branch' => 'main',
            'status' => 'success', 'duration_seconds' => 95, 'commit_hash' => 'abcdef1234',
        ]);

        app(DeployNotifier::class)->deploymentFinished($deployment->fresh());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/bottest-token/sendMessage')
                && $request['chat_id'] === '12345'
                && str_contains($request['text'], 'Deploy succeeded')
                && str_contains($request['text'], 'ci-site')
                && str_contains($request['text'], '1m 35s');
        });
    }

    public function test_notifier_is_silent_when_unconfigured(): void
    {
        config(['autopilot.telegram.bot_token' => null, 'autopilot.telegram.default_chat_id' => null]);
        Http::fake();

        $site = $this->makeSite();
        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id,
            'branch' => 'main', 'status' => 'success',
        ]);

        app(DeployNotifier::class)->deploymentFinished($deployment->fresh());

        Http::assertNothingSent();
    }

    /** A telegram outage must not surface as a deployment error. */
    public function test_notifier_swallows_transport_failures(): void
    {
        config(['autopilot.telegram.bot_token' => 'test-token', 'autopilot.telegram.default_chat_id' => '1']);
        Http::fake(fn () => throw new \RuntimeException('network down'));

        $site = $this->makeSite();
        $deployment = Deployment::create([
            'server_id' => $site->server_id, 'site_id' => $site->id,
            'branch' => 'main', 'status' => 'failed',
        ]);

        app(DeployNotifier::class)->deploymentFinished($deployment->fresh());

        $this->assertTrue(true, 'deploymentFinished() must never throw');
    }
}
