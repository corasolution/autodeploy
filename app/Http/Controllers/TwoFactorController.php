<?php

namespace App\Http\Controllers;

use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * UPGRADE-v2 Phase 6 — two-factor enrolment.
 *
 * Enrolment is two-step on purpose: generating a secret does NOT enable 2FA.
 * It is only confirmed once the user proves their authenticator produces a
 * matching code, so a mis-scanned QR can't lock them out of the dashboard that
 * holds every server's SSH credentials.
 */
class TwoFactorController extends Controller
{
    /** Step 1 — generate a secret and return the provisioning URI. */
    public function enroll(Request $request, TotpService $totp): JsonResponse
    {
        $user = $request->user();

        $secret = $totp->generateSecret();

        // Stored but NOT confirmed — hasTwoFactorEnabled() stays false until
        // confirm() succeeds, so login is unaffected if the user bails here.
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'uri' => $totp->provisioningUri(
                $secret,
                $user->email,
                config('app.name', 'AutoPilot Deploy'),
            ),
        ]);
    }

    /** Step 2 — prove the authenticator works, then switch 2FA on. */
    public function confirm(Request $request, TotpService $totp): RedirectResponse
    {
        $request->validate(['code' => 'required|string']);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return back()->with('error', 'Start the setup again — no pending secret was found.');
        }

        if (! $totp->verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages([
                'code' => 'That code is not valid. Make sure your device clock is correct and try again.',
            ]);
        }

        $codes = $totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        // Shown exactly once — they are hashed-at-rest equivalents (encrypted)
        // and never retrievable again.
        return back()->with([
            'success' => 'Two-factor authentication is on. Save these recovery codes now — they will not be shown again.',
            'recovery_codes' => $codes,
        ]);
    }

    /**
     * Disabling requires the current password: a hijacked session should not be
     * able to quietly remove the second factor.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate(['password' => 'required|string']);

        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => 'That password is incorrect.',
            ]);
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('success', 'Two-factor authentication disabled.');
    }
}
