<?php

namespace App\Jobs;

use App\Models\Deployment;
use App\Models\DeployLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class HealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public Deployment $deployment) {}

    public function handle(): void
    {
        $server   = $this->deployment->server;
        $endpoint = rtrim($server->app_url, '/') . '/health';

        try {
            $response = Http::timeout(10)->get($endpoint);
            $ok       = $response->status() === 200;

            DeployLog::record(
                $this->deployment->id, 5, 24,
                $ok ? 'success' : 'error',
                "GET {$endpoint}",
                "HTTP " . $response->status()
            );
        } catch (\Throwable $e) {
            DeployLog::record(
                $this->deployment->id, 5, 24, 'error',
                "GET {$endpoint}", $e->getMessage()
            );
        }
    }
}
