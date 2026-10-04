<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    protected $fillable = [
        'user_id', 'server_id', 'site_id', 'branch', 'with_data', 'with_database',
        'commit_hash', 'status', 'needs_attention',
        'release_path', 'previous_release',
        'triggered_by', 'ai_risk_level', 'ai_audit_result',
        'started_at', 'finished_at', 'duration_seconds',
    ];

    protected $casts = [
        'ai_audit_result' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'with_data' => 'boolean',
        'with_database' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(DeployLog::class);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function markStarted(): void
    {
        $this->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markFinished(string $status): void
    {
        $started = $this->started_at ?? now();
        $this->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_seconds' => (int) $started->diffInSeconds(now()),
        ]);
    }
}
