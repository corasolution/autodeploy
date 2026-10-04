<?php

namespace Tests\Unit;

use Anthropic\Laravel\Facades\Anthropic;
use App\Services\ClaudeAgentService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * UPGRADE-v2 Phase 7 (R1).
 *
 * Falling back is correct — an advisory feature must not fail a deploy — but
 * falling back SILENTLY meant a revoked key or an exhausted balance left the
 * AI layer looking healthy while doing nothing.
 *
 * Anthropic\Client is final, so the facade root is swapped for a stub rather
 * than mocked; that still drives the real call() path.
 */
class AiFallbackTest extends TestCase
{
    /** @param  \Closure|object  $messages  what messages() should return */
    private function swapAnthropic(callable $createHandler): void
    {
        $messages = new class($createHandler)
        {
            public function __construct(private $handler) {}

            public function create(array $payload)
            {
                return ($this->handler)($payload);
            }
        };

        Anthropic::swap(new class($messages)
        {
            public function __construct(private object $messages) {}

            public function messages(): object
            {
                return $this->messages;
            }
        });
    }

    private function reply(string $text): object
    {
        return (object) ['content' => [(object) ['text' => $text]]];
    }

    public function test_request_failure_is_logged_and_flagged(): void
    {
        Log::spy();

        $this->swapAnthropic(fn () => throw new \RuntimeException('credit balance is too low'));

        $result = (new ClaudeAgentService)->analysePostDeployLogs('some log lines');

        $this->assertTrue($result['fallback'], 'Callers must be able to tell a fallback from a real verdict');
        $this->assertSame('request_failed', $result['reason']);
        $this->assertStringContainsString('credit balance', $result['raw']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'Claude fallback used'
                && ($context['reason'] ?? null) === 'request_failed')
            ->once();
    }

    public function test_unparseable_reply_is_logged_and_flagged(): void
    {
        Log::spy();

        $this->swapAnthropic(fn () => $this->reply('I am afraid I cannot do that.'));

        $result = (new ClaudeAgentService)->diagnoseError('boom', '');

        $this->assertTrue($result['fallback']);
        $this->assertSame('invalid_json', $result['reason']);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context = []) => $message === 'Claude fallback used'
                && ($context['reason'] ?? null) === 'invalid_json')
            ->once();
    }

    /** A real verdict must NOT carry the fallback flag, or the UI cries wolf. */
    public function test_successful_call_is_not_flagged(): void
    {
        $this->swapAnthropic(fn () => $this->reply('{"status":"ok","issues":[],"severity_score":0}'));

        $result = (new ClaudeAgentService)->analysePostDeployLogs('clean logs');

        $this->assertArrayNotHasKey('fallback', $result);
        $this->assertSame('ok', $result['status']);
    }

    /** A fenced reply is still a real verdict, not a fallback. */
    public function test_fenced_json_is_parsed_not_flagged(): void
    {
        $this->swapAnthropic(fn () => $this->reply("```json\n{\"status\":\"warning\",\"issues\":[\"x\"]}\n```"));

        $result = (new ClaudeAgentService)->analysePostDeployLogs('logs');

        $this->assertArrayNotHasKey('fallback', $result);
        $this->assertSame('warning', $result['status']);
    }

    /** Log triage uses the fast model, diagnosis the smart one. */
    public function test_each_task_uses_its_configured_model(): void
    {
        $seen = [];
        $this->swapAnthropic(function (array $payload) use (&$seen) {
            $seen[] = $payload['model'];

            return $this->reply('{"status":"ok"}');
        });

        $service = new ClaudeAgentService;
        $service->analysePostDeployLogs('logs');
        $service->diagnoseError('err', '');

        $this->assertSame(config('autopilot.claude.model_fast'), $seen[0]);
        $this->assertSame(config('autopilot.claude.model_smart'), $seen[1]);
    }

    /**
     * The model ids are config, and wrong ones are a real failure mode — pin
     * the defaults so a typo is caught here rather than in production silence.
     */
    public function test_configured_model_defaults(): void
    {
        $this->assertSame('claude-sonnet-5', config('autopilot.claude.model_smart'));
        $this->assertSame('claude-haiku-4-5-20251001', config('autopilot.claude.model_fast'));
    }

    /** ping() reports the failure instead of swallowing it. */
    public function test_ping_reports_failure_detail(): void
    {
        $this->swapAnthropic(fn () => throw new \RuntimeException('Your credit balance is too low'));

        $result = (new ClaudeAgentService)->ping('smart');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('credit balance', $result['detail']);
        $this->assertSame(config('autopilot.claude.model_smart'), $result['model']);
    }
}
