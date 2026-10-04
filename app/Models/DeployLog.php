<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeployLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'deployment_id', 'phase', 'step', 'command',
        'output', 'exit_code', 'ai_diagnosis', 'status', 'logged_at',
    ];

    protected $casts = [
        'ai_diagnosis' => 'array',
        'logged_at' => 'datetime',
        'phase' => 'integer',
        'step' => 'integer',
        'exit_code' => 'integer',
    ];

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    public static function record(
        int $deploymentId,
        int $phase,
        int $step,
        string $status,
        ?string $command = null,
        ?string $output = null,
        ?int $exitCode = null,
        ?array $aiDiagnosis = null
    ): self {
        return self::create([
            'deployment_id' => $deploymentId,
            'phase' => $phase,
            'step' => $step,
            'status' => $status,
            'command' => $command,
            'output' => $output,
            'exit_code' => $exitCode,
            'ai_diagnosis' => $aiDiagnosis,
            'logged_at' => now(),
        ]);
    }
}
