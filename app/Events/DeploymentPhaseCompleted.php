<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeploymentPhaseCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Deployment $deployment,
        public int $phase,
        public string $message
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('deployments.' . $this->deployment->id)];
    }

    public function broadcastAs(): string
    {
        return 'phase.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'deployment_id' => $this->deployment->id,
            'phase'         => $this->phase,
            'message'       => $this->message,
            'status'        => $this->deployment->status,
        ];
    }
}
