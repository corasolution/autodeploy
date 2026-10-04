<?php

namespace Tests\Unit;

use App\Services\ClaudeAgentService;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 4 acceptance tests for the AI layer (F10).
 *
 * These exercise the pure helpers — fence stripping, masking, fallbacks — which
 * is where the bugs were. No API calls.
 */
class ClaudeAgentServiceTest extends TestCase
{
    private function invoke(string $method, array $args = []): mixed
    {
        $service = new ClaudeAgentService;

        return (new \ReflectionMethod($service, $method))->invokeArgs($service, $args);
    }

    public function test_json_fence_is_stripped(): void
    {
        $fenced = "```json\n{\"status\": \"ok\"}\n```";

        $this->assertSame('{"status": "ok"}', $this->invoke('stripJsonFence', [$fenced]));
    }

    public function test_bare_fence_without_language_is_stripped(): void
    {
        $this->assertSame('{"a":1}', $this->invoke('stripJsonFence', ["```\n{\"a\":1}\n```"]));
    }

    public function test_unfenced_json_is_returned_untouched(): void
    {
        $this->assertSame('{"a":1}', $this->invoke('stripJsonFence', ['{"a":1}']));
    }

    /**
     * Previously only auditConfigSafe() masked, so diagnoseError() posted raw
     * log lines — which routinely carry DSNs and tokens.
     */
    public function test_env_style_secrets_are_masked(): void
    {
        $masked = $this->invoke('maskSecrets', [
            "DB_PASSWORD=hunter2\nAPP_KEY=base64:abcdef\nANTHROPIC_API_KEY=sk-ant-xyz123\nAPP_NAME=Keep",
        ]);

        $this->assertStringNotContainsString('hunter2', $masked);
        $this->assertStringNotContainsString('base64:abcdef', $masked);
        $this->assertStringNotContainsString('sk-ant-xyz123', $masked);

        // Non-secret keys must survive, or the audit has nothing to work with.
        $this->assertStringContainsString('APP_NAME=Keep', $masked);
    }

    public function test_arbitrary_secret_and_token_keys_are_masked(): void
    {
        $masked = $this->invoke('maskSecrets', ["PUSHER_APP_SECRET=abc123\nSTRIPE_TOKEN=tok_live_9\n"]);

        $this->assertStringNotContainsString('abc123', $masked);
        $this->assertStringNotContainsString('tok_live_9', $masked);
    }

    public function test_dsn_inline_credentials_are_masked(): void
    {
        $masked = $this->invoke('maskSecrets', ['mysql://appuser:s3cr3t@db.internal:3306/app']);

        $this->assertStringNotContainsString('s3cr3t', $masked);
        $this->assertStringContainsString('appuser', $masked);
    }

    public function test_bearer_tokens_in_stack_traces_are_masked(): void
    {
        $masked = $this->invoke('maskSecrets', ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9']);

        $this->assertStringNotContainsString('eyJhbGciOiJIUzI1NiJ9', $masked);
        $this->assertStringContainsString('Bearer ***', $masked);
    }

    /** Log triage uses the fast model; judgement calls use the smart one. */
    public function test_models_are_split_by_workload(): void
    {
        $service = new ClaudeAgentService;

        $fast = (new \ReflectionProperty($service, 'modelFast'))->getValue($service);
        $smart = (new \ReflectionProperty($service, 'modelSmart'))->getValue($service);

        $this->assertSame('claude-haiku-4-5-20251001', $fast);
        $this->assertSame('claude-sonnet-5', $smart);
        $this->assertNotSame($fast, $smart);
    }
}
