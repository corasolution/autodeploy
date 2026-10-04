<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;

class CleanSnapshotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $serverId) {}

    public function handle(): void
    {
        $server      = Server::findOrFail($this->serverId);
        $keep        = config('autopilot.deploy.keep_snapshots', 5);
        $snapshotDir = config('autopilot.deploy.snapshots_path');

        $deploymentIds = $server->deployments()
            ->where('status', 'success')
            ->latest()
            ->pluck('id')
            ->toArray();

        $toKeep = array_slice($deploymentIds, 0, $keep);
        $allIds = array_slice($deploymentIds, $keep);

        foreach ($allIds as $id) {
            $path = $snapshotDir . DIRECTORY_SEPARATOR . $id;
            if (is_dir($path)) {
                File::deleteDirectory($path);
            }
        }
    }
}
