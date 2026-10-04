<?php

namespace App\Services;

use Anthropic\Laravel\Facades\Anthropic;
use RuntimeException;

class ClaudeAgentService
{
    private string $model;
    private int $maxTokens;

    public function __construct()
    {
        $this->model     = config('autopilot.claude.model', 'claude-sonnet-4-20250514');
        $this->maxTokens = config('autopilot.claude.max_tokens', 4096);
    }

    public function auditConfig(string $envDiff, array $migrations): array
    {
        $prompt = "Analyse the provided .env diff and migration list.\n"
            . "Identify breaking changes, missing env keys, and risky migrations.\n"
            . "Return ONLY valid JSON: { \"risk_level\": \"low|medium|high\", \"warnings\": [], \"suggestions\": [] }\n\n"
            . "ENV DIFF:\n{$envDiff}\n\nMIGRATIONS:\n" . implode("\n", $migrations);

        return $this->call($prompt);
    }

    public function auditConfigSafe(string $envDiff, array $migrations): array
    {
        return $this->auditConfig($this->maskSecrets($envDiff), $migrations);
    }

    public function diagnoseError(string $errorLog, string $stderr): array
    {
        $prompt = "You are a Laravel deployment expert.\n"
            . "Analyse this deployment error log and SSH stderr output.\n"
            . "Return ONLY valid JSON: { \"cause\": \"\", \"fix\": \"\", \"safe_to_retry\": true, \"rollback_recommended\": false }\n\n"
            . "ERROR LOG:\n{$errorLog}\n\nSTDERR:\n{$stderr}";

        return $this->call($prompt);
    }

    public function analysePostDeployLogs(string $laravelLogs): array
    {
        $prompt = "Review the last 100 lines of Laravel logs after deployment.\n"
            . "Identify any new exceptions, queue failures, or config issues.\n"
            . "Return ONLY valid JSON: { \"status\": \"ok|warning|critical\", \"issues\": [], \"severity_score\": 0 }\n\n"
            . "LARAVEL LOGS:\n{$laravelLogs}";

        return $this->call($prompt);
    }

    private function call(string $prompt): array
    {
        $response = Anthropic::messages()->create([
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        $content = $response->content[0]->text ?? '{}';
        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Claude returned invalid JSON: ' . $content);
        }

        return $decoded;
    }

    private function maskSecrets(string $text): string
    {
        return preg_replace([
            '/DB_PASSWORD=.+/i',
            '/APP_KEY=.+/i',
            '/_SECRET=.+/i',
            '/_TOKEN=.+/i',
            '/ANTHROPIC_API_KEY=.+/i',
        ], [
            'DB_PASSWORD=***',
            'APP_KEY=***',
            '_SECRET=***',
            '_TOKEN=***',
            'ANTHROPIC_API_KEY=***',
        ], $text);
    }
}
