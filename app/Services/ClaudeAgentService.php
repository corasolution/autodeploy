<?php

namespace App\Services;

use Anthropic\Laravel\Facades\Anthropic;
use Illuminate\Support\Facades\Log;

/**
 * UPGRADE-v2 Phase 4.
 *
 * Three changes of substance over the original:
 *
 * 1. Two models instead of one. Log triage is high-volume pattern matching and
 *    runs on the fast model; diagnosis and config audit are judgement calls and
 *    run on the smart one.
 * 2. Never throws. A deploy must not fail because a model returned prose
 *    instead of JSON, so an unparseable reply degrades to
 *    ['status' => 'unknown', 'raw' => …].
 * 3. Secrets are masked on EVERY prompt. Previously only auditConfigSafe()
 *    masked, but Laravel logs routinely contain DSNs, bearer tokens and
 *    connection strings — exactly what gets posted in diagnoseError().
 */
class ClaudeAgentService
{
    private string $modelFast;

    private string $modelSmart;

    private int $maxTokens;

    public function __construct()
    {
        $this->modelFast = config('autopilot.claude.model_fast', 'claude-haiku-4-5-20251001');
        $this->modelSmart = config('autopilot.claude.model_smart', 'claude-sonnet-5');
        $this->maxTokens = (int) config('autopilot.claude.max_tokens', 2048);
    }

    /**
     * Pre-deploy risk audit. `high` gates the deployment behind approval, so
     * this one gets the smart model.
     */
    public function auditConfig(string $envDiff, array $migrations): array
    {
        $prompt = "Analyse the provided .env key diff and pending migration list.\n"
            .'Identify breaking changes, missing env keys, and risky (destructive, '
            ."non-reversible, or long-locking) migrations.\n"
            ."Return ONLY valid JSON: { \"risk_level\": \"low|medium|high\", \"warnings\": [], \"suggestions\": [] }\n\n"
            // R4: each entry is a migration name followed by its source, so the
            // model can tell an additive column from a dropColumn. Separated by
            // a rule because the bodies are multi-line.
            ."ENV KEY DIFF (names only — values are never sent):\n{$envDiff}\n\n"
            ."PENDING MIGRATIONS (name, then source):\n"
            .implode("\n\n---\n\n", $migrations);

        return $this->call($prompt, $this->modelSmart,
            'You are a Laravel deployment risk auditor. Respond with JSON only.',
            ['risk_level' => 'unknown', 'warnings' => [], 'suggestions' => []],
        );
    }

    /**
     * @deprecated Masking is now unconditional in call(); kept so existing
     *             callers (DeployAuditCommand) keep working.
     */
    public function auditConfigSafe(string $envDiff, array $migrations): array
    {
        return $this->auditConfig($envDiff, $migrations);
    }

    public function diagnoseError(string $errorLog, string $stderr): array
    {
        $prompt = "You are a Laravel deployment expert.\n"
            ."Analyse this deployment error log and command output.\n"
            ."Return ONLY valid JSON: { \"cause\": \"\", \"fix\": \"\", \"safe_to_retry\": true, \"rollback_recommended\": false }\n\n"
            ."ERROR LOG:\n{$errorLog}\n\nCOMMAND OUTPUT:\n{$stderr}";

        return $this->call($prompt, $this->modelSmart,
            'You are a Laravel deployment expert. Respond with JSON only.',
            ['cause' => 'unknown', 'fix' => '', 'safe_to_retry' => false, 'rollback_recommended' => false],
        );
    }

    public function analysePostDeployLogs(string $laravelLogs): array
    {
        $prompt = "Review these Laravel log lines captured just after a deployment.\n"
            ."Identify NEW exceptions, queue failures, or configuration problems.\n"
            ."Return ONLY valid JSON: { \"status\": \"ok|warning|critical\", \"issues\": [], \"severity_score\": 0 }\n\n"
            ."LARAVEL LOGS:\n{$laravelLogs}";

        return $this->call($prompt, $this->modelFast,
            'You triage Laravel logs after a deploy. Respond with JSON only.',
            ['status' => 'unknown', 'issues' => [], 'severity_score' => 0],
        );
    }

    /**
     * Send one prompt and parse the JSON reply.
     *
     * R1: degrading to a fallback is correct — an advisory feature must never
     * fail a deploy — but doing it SILENTLY was a bug. A wrong model id or a
     * revoked key made the whole AI layer look healthy while doing nothing.
     * Every fallback is now logged and carries `fallback => true`, so callers
     * and the UI can say "AI unavailable" instead of showing an empty verdict.
     *
     * @param  array  $fallback  returned (plus `fallback` and `raw`) on any failure
     */
    private function call(string $prompt, string $model, string $system, array $fallback): array
    {
        try {
            $response = Anthropic::messages()->create([
                'model' => $model,
                'max_tokens' => $this->maxTokens,
                // Role goes in `system`, not smuggled into the user turn.
                'system' => $system,
                'messages' => [
                    // Masked here so no caller can forget.
                    ['role' => 'user', 'content' => $this->maskSecrets($prompt)],
                ],
            ]);

            $content = $response->content[0]->text ?? '';
        } catch (\Throwable $e) {
            // Network failure, bad key, bad model id, rate limit.
            return $this->fallback($fallback, $model, 'request_failed', $e->getMessage());
        }

        $decoded = json_decode($this->stripJsonFence($content), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $this->fallback($fallback, $model, 'invalid_json', $content);
        }

        return $decoded;
    }

    /**
     * Build and log a fallback result. The reason is truncated — a model can
     * return a lot of prose, and this goes to the application log.
     */
    private function fallback(array $fallback, string $model, string $reason, string $detail): array
    {
        Log::warning('Claude fallback used', [
            'model' => $model,
            'reason' => $reason,
            'detail' => mb_substr(trim($detail), 0, 500),
        ]);

        return $fallback + [
            'fallback' => true,
            'reason' => $reason,
            'raw' => $detail,
        ];
    }

    /**
     * Round-trip both configured models. Used by `autopilot:ai-ping` to prove
     * the AI layer actually works, rather than inferring it from silence.
     *
     * @return array{ok: bool, model: string, detail: string}
     */
    public function ping(string $which = 'smart'): array
    {
        $model = $which === 'fast' ? $this->modelFast : $this->modelSmart;

        try {
            $response = Anthropic::messages()->create([
                'model' => $model,
                'max_tokens' => 64,
                'system' => 'Reply with JSON only.',
                'messages' => [
                    ['role' => 'user', 'content' => 'Return exactly: {"ok": true}'],
                ],
            ]);

            $text = trim($response->content[0]->text ?? '');

            return ['ok' => true, 'model' => $model, 'detail' => $text];
        } catch (\Throwable $e) {
            return ['ok' => false, 'model' => $model, 'detail' => $e->getMessage()];
        }
    }

    /**
     * Models often wrap JSON in a ```json fence despite being asked not to.
     * That used to throw and abort the surrounding step.
     */
    private function stripJsonFence(string $text): string
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return $text;
    }

    /**
     * Mask anything that looks like a credential before it leaves the machine.
     *
     * Covers both `KEY=value` (env files) and `KEY: value` / `"key": "value"`
     * shapes that show up in log lines and stack traces.
     */
    private function maskSecrets(string $text): string
    {
        $patterns = [
            '/\b(DB_PASSWORD|APP_KEY|ANTHROPIC_API_KEY|MAIL_PASSWORD|REDIS_PASSWORD)\s*[=:]\s*\S+/i',
            '/\b([A-Z0-9_]*(?:SECRET|TOKEN|PASSWORD|API_KEY))\s*[=:]\s*\S+/i',
            // Inline credentials in a DSN: mysql://user:pass@host
            '/(\/\/[^:\/\s]+):([^@\s]+)@/',
            // Bearer tokens and Anthropic keys appearing raw in a stack trace.
            '/\bBearer\s+[A-Za-z0-9._\-]+/i',
            '/\bsk-ant-[A-Za-z0-9._\-]+/i',
        ];

        $replacements = [
            '$1=***',
            '$1=***',
            '$1:***@',
            'Bearer ***',
            'sk-ant-***',
        ];

        return preg_replace($patterns, $replacements, $text) ?? $text;
    }
}
