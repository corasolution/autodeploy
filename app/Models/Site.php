<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Contracts\Encryption\DecryptException;

class Site extends Model
{
    protected $fillable = [
        'server_id', 'name', 'deploy_path', 'source_path', 'repo_url', 'branch', 'run_seeders', 'run_tenant_migrations', 'grant_createdb',
        'pre_migrate_commands', 'php_binary', 'app_url', 'env_content', 'active',
    ];

    protected $hidden = ['env_content'];

    protected $casts = [
        'active'                  => 'boolean',
        'run_seeders'             => 'boolean',
        'run_tenant_migrations'   => 'boolean',
        'grant_createdb'          => 'boolean',
    ];

    public function setEnvContentAttribute(?string $value): void
    {
        $this->attributes['env_content'] = $value ? encrypt($value) : null;
    }

    public function getEnvContentAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (DecryptException) {
            // Orphaned ciphertext (encrypted under a previous APP_KEY) —
            // treat as empty so pages load and the value can be re-entered.
            return null;
        }
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function lastDeployment(): ?Deployment
    {
        return $this->deployments()->latest()->first();
    }
}
