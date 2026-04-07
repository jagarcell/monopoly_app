<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AppleAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class AppleController extends Controller
{
    /**
     * @param  AppleAuthService  $appleAuthService
     */
    public function __construct(
        private readonly AppleAuthService $appleAuthService,
    ) {}

    /**
     * Redirect the user to Apple's OAuth consent screen.
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     *
     * Logic: Uses Laravel Socialite to build the Apple Sign In redirect URL and
     * sends the user to Apple for authentication. The 'form_post' response mode
     * is required by Apple so the callback arrives as a POST request.
     */
    public function redirect(): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver('apple')->redirect();
    }

    /**
     * Handle the callback from Apple after the user authenticates.
     *
     * @return RedirectResponse
     *
     * Logic: Receives the OAuth callback POSTed by Apple, retrieves the authenticated
     * user data via Socialite, delegates user lookup/creation to AppleAuthService,
     * logs the user in with a session, and redirects to the dashboard. On failure,
     * logs the error and redirects back to login with a flash message. Apple always
     * POSTs the callback (form_post response mode), so this route must accept POST.
     */
    public function callback(): RedirectResponse
    {
        try {
            $appleUser = Socialite::driver('apple')->user();
        } catch (\Exception $e) {
            Log::error('Apple Sign In callback failed to retrieve user.', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('login')->with('status', 'Apple Sign In failed. Please try again.');
        }

        try {
            $user = $this->appleAuthService->findOrCreateUser($appleUser);

            Auth::login($user, remember: true);

            session()->regenerate();

            return redirect()->intended(route('dashboard', absolute: false));
        } catch (\Exception $e) {
            Log::error('Apple Sign In user creation/login failed.', [
                'error'    => $e->getMessage(),
                'apple_id' => $appleUser->getId(),
                'email'    => $appleUser->getEmail(),
            ]);

            return redirect()->route('login')->with('status', 'Apple Sign In failed. Please try again.');
        }
    }
}
