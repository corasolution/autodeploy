<?php

namespace App\Services;

use App\Models\Deployment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UPGRADE-v2 Phase 5 — Telegram deploy notifications.
 *
 * Entirely best-effort: a notification failure must never affect a deployment's
 * outcome, so every path here swallows its errors into the application log.
 */
class DeployNotifier
{
    public function deploymentFinished(Deployment $deployment): void
    {
        $message = match ($deployment->status) {
            'success' => $this->successMessage($deployment),
            'failed' => $this->failureMessage($deployment),
            'rolled_back' => $this->rolledBackMessage($deployment),
            'awaiting_approval' => $this->approvalMessage($deployment),
            default => null,
        };

        if ($message !== null) {
            $this->send($deployment, $message);
        }
    }

    private function successMessage(Deployment $deployment): string
    {
        $lines = [
            '✅ *Deploy succeeded*',
            $this->context($deployment),
        ];

        if ($deployment->duration_seconds) {
            $lines[] = 'Duration: '.$this->humanDuration($deployment->duration_seconds);
        }

        if ($deployment->needs_attention) {
            $lines[] = '⚠️ AI log triage flagged issues — worth a look.';
        }

        return implode("\n", $lines);
    }

    private function failureMessage(Deployment $deployment): string
    {
        $lines = ['❌ *Deploy failed*', $this->context($deployment)];

        // Prefer the step that actually failed over the generic exception.
        $failed = $deployment->logs()
            ->where('status', 'error')
            ->orderByDesc('id')
            ->first();

        if ($failed) {
            $lines[] = 'Failed at phase '.$failed->phase.' step '.$failed->step;

            $diagnosis = $failed->ai_diagnosis;
            if (is_array($diagnosis) && ! empty($diagnosis['cause'])) {
                $lines[] = '';
                $lines[] = 'Cause: '.$this->truncate((string) $diagnosis['cause'], 300);

                if (! empty($diagnosis['fix'])) {
                    $lines[] = 'Fix: '.$this->truncate((string) $diagnosis['fix'], 300);
                }
            } elseif ($failed->output) {
                $lines[] = '';
                $lines[] = '```';
                $lines[] = $this->truncate(trim($failed->output), 400);
                $lines[] = '```';
            }
        }

        return implode("\n", $lines);
    }

    private function rolledBackMessage(Deployment $deployment): string
    {
        return implode("\n", [
            '↩️ *Deploy rolled back*',
            $this->context($deployment),
            $deployment->previous_release
                ? 'Restored release: '.basename($deployment->previous_release)
                : 'The previous release was restored.',
            '',
            'Note: the database schema was NOT reverted — only the code.',
        ]);
    }

    private function approvalMessage(Deployment $deployment): string
    {
        $audit = $deployment->ai_audit_result;
        $warnings = is_array($audit) ? ($audit['warnings'] ?? []) : [];

        $lines = [
            '⏸️ *Deploy awaiting approval*',
            $this->context($deployment),
            'The pre-flight audit rated this deploy *high risk*. Nothing has been '
                .'uploaded or changed on the server.',
        ];

        foreach (array_slice((array) $warnings, 0, 5) as $warning) {
            $lines[] = '• '.$this->truncate((string) $warning, 200);
        }

        $lines[] = '';
        $lines[] = $this->deploymentUrl($deployment);

        return implode("\n", $lines);
    }

    private function context(Deployment $deployment): string
    {
        $site = $deployment->site?->name ?? 'unknown site';
        $server = $deployment->server?->name ?? 'unknown server';
        $parts = ["*{$site}* on {$server}"];

        if ($deployment->commit_hash) {
            $parts[] = 'commit '.substr($deployment->commit_hash, 0, 8);
        }

        return implode(' · ', $parts);
    }

    private function deploymentUrl(Deployment $deployment): string
    {
        return rtrim((string) config('app.url'), '/').'/deployments/'.$deployment->id;
    }

    private function send(Deployment $deployment, string $message): void
    {
        $token = config('autopilot.telegram.bot_token');
        $chatId = $deployment->server?->telegram_chat_id
            ?: config('autopilot.telegram.default_chat_id');

        // Not configured is the normal case, not an error.
        if (! $token || ! $chatId) {
            return;
        }

        try {
            $response = Http::timeout(10)->post(
                "https://api.telegram.org/bot{$token}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                    'disable_web_page_preview' => true,
                ],
            );

            if ($response->failed()) {
                Log::warning('Telegram deploy notification failed', [
                    'deployment_id' => $deployment->id,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Telegram deploy notification error: '.$e->getMessage(), [
                'deployment_id' => $deployment->id,
            ]);
        }
    }

    private function truncate(string $text, int $limit): string
    {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit).'…';
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        return intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }
}
