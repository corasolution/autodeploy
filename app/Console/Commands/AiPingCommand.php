<?php

namespace App\Console\Commands;

use App\Services\ClaudeAgentService;
use Illuminate\Console\Command;

/**
 * UPGRADE-v2 Phase 7 (R1).
 *
 * The AI layer degrades to a fallback on any failure, which is right for a
 * deploy but means a wrong model id or a revoked key is invisible. This proves
 * both configured models actually answer.
 */
class AiPingCommand extends Command
{
    protected $signature = 'autopilot:ai-ping';

    protected $description = 'Check that both configured Claude models respond';

    public function handle(ClaudeAgentService $claude): int
    {
        if (! config('autopilot.claude.api_key')) {
            $this->error('ANTHROPIC_API_KEY is not set — the AI layer will always fall back.');

            return self::FAILURE;
        }

        $failed = false;

        foreach (['fast' => 'Log triage', 'smart' => 'Diagnosis / risk audit'] as $which => $role) {
            $result = $claude->ping($which);

            if ($result['ok']) {
                $this->line(sprintf(
                    '  <fg=green>OK  </> %-9s %-34s %s',
                    $which, $result['model'], $result['detail'],
                ));
            } else {
                $failed = true;
                $this->line(sprintf('  <fg=red>FAIL</> %-9s %s', $which, $result['model']));
                $this->line('       '.$result['detail']);
                $this->line('       <fg=yellow>'.$this->interpret($result['detail']).'</>');
            }

            $this->line("       <fg=gray>{$role}</>");
        }

        $this->newLine();

        if ($failed) {
            $this->error('At least one model failed. Until this is fixed the AI layer silently returns empty verdicts.');
            $this->line('Check AUTOPILOT_MODEL_FAST / AUTOPILOT_MODEL_SMART and ANTHROPIC_API_KEY in .env.');

            return self::FAILURE;
        }

        $this->info('Both models responded.');

        return self::SUCCESS;
    }

    /**
     * Name the actual cause.
     *
     * Billing and auth errors are returned BEFORE the model id is validated —
     * verified by sending a deliberately bogus model and getting the same
     * credit-balance error. So while billing is failing, a failed ping says
     * nothing about whether the model id is correct, and chasing the model
     * string is a dead end.
     */
    private function interpret(string $detail): string
    {
        $detail = strtolower($detail);

        return match (true) {
            str_contains($detail, 'credit balance') => 'Account has no API credit. This is a billing issue, NOT a model id problem — '
                .'the API rejects on balance before it validates the model, so the model ids cannot be checked until this is resolved.',
            str_contains($detail, 'authentication') || str_contains($detail, 'invalid x-api-key') => 'ANTHROPIC_API_KEY is wrong or revoked.',
            str_contains($detail, 'not_found') || str_contains($detail, 'model') && str_contains($detail, 'not found') => 'Model id is not recognised — check AUTOPILOT_MODEL_FAST / AUTOPILOT_MODEL_SMART.',
            str_contains($detail, 'rate') => 'Rate limited — transient, retry.',
            default => 'Unrecognised failure; the raw message above is the authority.',
        };
    }
}
