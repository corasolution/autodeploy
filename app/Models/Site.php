<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    protected $fillable = [
        'server_id', 'name', 'deploy_path', 'source_path', 'repo_url', 'branch', 'run_seeders', 'run_tenant_migrations', 'grant_createdb',
        'pre_migrate_commands', 'php_binary', 'app_url', 'env_content', 'active',
        'webhook_secret', 'environment',
    ];

    protected $hidden = ['env_content', 'webhook_secret'];

    protected $casts = [
        'active' => 'boolean',
        'run_seeders' => 'boolean',
        'run_tenant_migrations' => 'boolean',
        'grant_createdb' => 'boolean',
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

    public function setWebhookSecretAttribute(?string $value): void
    {
        $this->attributes['webhook_secret'] = $value ? encrypt($value) : null;
    }

    public function getWebhookSecretAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (DecryptException) {
            // Encrypted under a previous APP_KEY — treat as unset so the
            // webhook fails closed (401) rather than authenticating on garbage.
            return null;
        }
    }

    /**
     * Pushing a local database over this site's remote one is gated on an
     * explicit name confirmation when the site is production (F7).
     */
    public function isProduction(): bool
    {
        return ($this->environment ?? 'production') === 'production';
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
