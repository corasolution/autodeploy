<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $hidden = [
        'password', 'remember_token',
        // Never serialise these into an Inertia page payload.
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // ── Two-factor auth (UPGRADE-v2 Phase 6) ─────────────────────────────────

    public function setTwoFactorSecretAttribute(?string $value): void
    {
        $this->attributes['two_factor_secret'] = $value ? encrypt($value) : null;
    }

    public function getTwoFactorSecretAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return decrypt($value);
        } catch (DecryptException) {
            // Encrypted under a previous APP_KEY — treat as unset so the user
            // can re-enrol rather than being locked out by an undecryptable
            // secret they can never satisfy.
            return null;
        }
    }

    public function setTwoFactorRecoveryCodesAttribute(?array $value): void
    {
        $this->attributes['two_factor_recovery_codes'] = $value ? encrypt(json_encode($value)) : null;
    }

    public function getTwoFactorRecoveryCodesAttribute(?string $value): array
    {
        if (! $value) {
            return [];
        }

        try {
            return json_decode(decrypt($value), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }

    /** Enrolment is only complete once a code has been confirmed. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Consume a single-use recovery code. Returns false if it isn't valid, so
     * a code can never be replayed.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $code = strtoupper(trim($code));
        $codes = $this->two_factor_recovery_codes;

        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);
        $this->two_factor_recovery_codes = array_values($codes);
        $this->save();

        return true;
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }
}
