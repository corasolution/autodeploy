<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Services\DeployNotifier;
use App\Services\DeployService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDeploymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Sized for archives up to ~1GB on a typical VPS link.
    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public Deployment $deployment)
    {
        $this->onQueue('deployments');
    }

    public function handle(DeployService $deployService, DeployNotifier $notifier): void
    {
        // ZipArchive holds the central directory in memory; a 1GB tree with
        // many small files can push well past the default 128M limit.
        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);

        try {
            $deployService->run($this->deployment);
        } finally {
            // Phase 5: notify on every terminal outcome — success, failure,
            // rollback, or held for approval. Best-effort, never throws, and in
            // `finally` so a failed deploy still reports.
            $notifier->deploymentFinished($this->deployment->fresh());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->deployment->markFinished('failed');

        // handle()'s finally block doesn't run when the job itself is killed
        // (timeout, worker restart), so cover that path too.
        app(DeployNotifier::class)->deploymentFinished($this->deployment->fresh());
    }
}
