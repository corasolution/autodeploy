<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    /**
     * Decrypt a stored value, returning null if it was encrypted under a
     * different APP_KEY (orphaned ciphertext) instead of throwing.
     */
    protected function safeDecrypt(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (DecryptException) {
            return null;
        }
    }

    protected $fillable = [
        'user_id', 'name', 'panel_type', 'host', 'ssh_port', 'ssh_user', 'web_user',
        'ssh_auth', 'ssh_password', 'ssh_private_key',
        'panel_url', 'panel_token', 'webhook_secret', 'active', 'telegram_chat_id',
    ];

    protected $hidden = ['ssh_password', 'ssh_private_key', 'panel_token', 'webhook_secret'];

    protected $casts = [
        'active' => 'boolean',
        'ssh_port' => 'integer',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function setSshPasswordAttribute(?string $value): void
    {
        $this->attributes['ssh_password'] = $value ? encrypt($value) : null;
    }

    public function getSshPasswordAttribute(?string $value): ?string
    {
        return $this->safeDecrypt($value);
    }

    public function setSshPrivateKeyAttribute(?string $value): void
    {
        $this->attributes['ssh_private_key'] = $value ? encrypt($value) : null;
    }

    public function getSshPrivateKeyAttribute(?string $value): ?string
    {
        return $this->safeDecrypt($value);
    }

    public function setPanelTokenAttribute(?string $value): void
    {
        $this->attributes['panel_token'] = $value ? encrypt($value) : null;
    }

    public function getPanelTokenAttribute(?string $value): ?string
    {
        return $this->safeDecrypt($value);
    }

    public function setWebhookSecretAttribute(?string $value): void
    {
        $this->attributes['webhook_secret'] = $value ? encrypt($value) : null;
    }

    public function getWebhookSecretAttribute(?string $value): ?string
    {
        return $this->safeDecrypt($value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lastDeployment(): ?Deployment
    {
        return $this->deployments()->latest()->first();
    }
}
