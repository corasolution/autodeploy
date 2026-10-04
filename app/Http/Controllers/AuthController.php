<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLogin(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Validate the password WITHOUT logging in, so a user with 2FA is never
        // authenticated on one factor. Auth::attempt() would create the session
        // immediately, and anything that happened before the second factor
        // (a crash, a closed tab) would leave them logged in.
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors([
                'email' => 'These credentials do not match our records.',
            ])->onlyInput('email');
        }

        if ($user->hasTwoFactorEnabled()) {
            // Park the user id in the session — not the user object — and let
            // the challenge complete the login.
            $request->session()->put('2fa:user', [
                'id' => $user->id,
                'remember' => $request->boolean('remember'),
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function showTwoFactorChallenge(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('2fa:user')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TwoFactorChallenge');
    }

    /**
     * Second factor: a TOTP code, or a single-use recovery code.
     */
    public function verifyTwoFactor(Request $request, TotpService $totp): RedirectResponse
    {
        $pending = $request->session()->get('2fa:user');

        if (! $pending) {
            return redirect()->route('login');
        }

        $request->validate(['code' => 'required|string']);

        $user = User::find($pending['id']);

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget('2fa:user');

            return redirect()->route('login');
        }

        $code = trim($request->input('code'));

        $verified = $totp->verify($user->two_factor_secret, $code)
            || $user->consumeRecoveryCode($code);

        if (! $verified) {
            return back()->withErrors(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }

        $request->session()->forget('2fa:user');

        Auth::login($user, (bool) ($pending['remember'] ?? false));
        $request->session()->regenerate();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
