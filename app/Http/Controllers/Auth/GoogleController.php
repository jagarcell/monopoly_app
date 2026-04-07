<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    /**
     * @param  GoogleAuthService  $googleAuthService
     */
    public function __construct(
        private readonly GoogleAuthService $googleAuthService,
    ) {}

    /**
     * Redirect the user to Google's OAuth consent screen.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * Logic: Uses Laravel Socialite to build the Google OAuth redirect URL and
     * sends the user to Google for authentication.
     */
    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle the callback from Google after the user authenticates.
     *
     * @return RedirectResponse
     *
     * Logic: Receives the OAuth callback from Google, retrieves the authenticated
     * user data via Socialite, delegates user lookup/creation to GoogleAuthService,
     * logs the user in with a session, and redirects to the dashboard. On failure,
     * logs the error and redirects back to login with a flash message.
     */
    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            Log::error('Google OAuth callback failed to retrieve user.', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->with('status', 'Google authentication failed. Please try again.');
        }

        try {
            $user = $this->googleAuthService->findOrCreateUser($googleUser);

            Auth::login($user, remember: true);

            session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        } catch (\Exception $e) {
            Log::error('Google OAuth user creation/login failed.', [
                'error' => $e->getMessage(),
                'google_id' => $googleUser->getId(),
                'email' => $googleUser->getEmail(),
            ]);

            return redirect()->route('login')->with('status', 'Google authentication failed. Please try again.');
        }
    }
}
