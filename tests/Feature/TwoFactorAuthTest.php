<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** UPGRADE-v2 Phase 6 — two-factor auth acceptance tests. */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function userWith2fa(): array
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();

        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse'),
        ]);

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['AAAA1111-BBBB2222'],
        ])->save();

        return [$user->fresh(), $secret, $totp];
    }

    /**
     * The key property: a correct password alone must NOT create a session.
     */
    public function test_password_alone_does_not_log_in_a_2fa_user(): void
    {
        [$user] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertRedirect('/two-factor');

        $this->assertGuest();
    }

    public function test_valid_totp_completes_login(): void
    {
        [$user, $secret, $totp] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
        $this->assertGuest();

        $this->post('/two-factor', ['code' => $totp->codeAt($secret, time())])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_totp_is_rejected(): void
    {
        [$user] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

        $this->post('/two-factor', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_works_once_only(): void
    {
        [$user] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
        $this->post('/two-factor', ['code' => 'AAAA1111-BBBB2222'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // Consumed — a replay must fail.
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
        $this->post('/two-factor', ['code' => 'AAAA1111-BBBB2222'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_user_without_2fa_logs_in_directly(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    /** Generating a secret must not switch 2FA on — a mis-scan would lock the user out. */
    public function test_enrolment_does_not_enable_until_confirmed(): void
    {
        $user = User::factory()->create();

        $secret = $this->actingAs($user)->postJson('/two-factor/enroll')->assertOk()->json('secret');

        $this->assertNotNull($secret);
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled(), '2FA must stay off until a code is confirmed');

        $this->actingAs($user)
            ->post('/two-factor/confirm', ['code' => (new TotpService)->codeAt($secret, time())])
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_disabling_requires_the_current_password(): void
    {
        [$user] = $this->userWith2fa();

        $this->actingAs($user)->delete('/two-factor', ['password' => 'wrong'])
            ->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        $this->actingAs($user)->delete('/two-factor', ['password' => 'correct-horse'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_secrets_are_encrypted_and_never_serialised(): void
    {
        [$user, $secret] = $this->userWith2fa();

        $stored = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $stored, 'Secret must be encrypted at rest');

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());
    }

    public function test_login_is_rate_limited(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

        // 5/min per email+IP — the 6th must be throttled, not merely rejected.
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertStatus(429);
    }
}
