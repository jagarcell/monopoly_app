<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\GoogleAuthRepository;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class GoogleAuthService
{
    /**
     * @param  GoogleAuthRepository  $repository
     */
    public function __construct(
        private readonly GoogleAuthRepository $repository,
    ) {}

    /**
     * Find or create a user from a Google OAuth response.
     *
     * @param  SocialiteUser  $googleUser  The user data returned by Socialite.
     * @return User
     *
     * Logic: Attempts to find an existing user by google_id first. If not found,
     * falls back to finding by email to handle users who registered locally before
     * using Google. Links the Google account if found by email. Creates a brand-new
     * user if neither lookup succeeds.
     */
    public function findOrCreateUser(SocialiteUser $googleUser): User
    {
        $user = $this->repository->findByGoogleId($googleUser->getId());

        if ($user) {
            return $user;
        }

        $user = $this->repository->findByEmail($googleUser->getEmail());

        if ($user) {
            Log::info('Linking Google account to existing user.', [
                'user_id' => $user->id,
                'google_id' => $googleUser->getId(),
            ]);

            return $this->repository->linkGoogleAccount(
                $user,
                $googleUser->getId(),
                $googleUser->getAvatar(),
            );
        }

        Log::info('Creating new user from Google OAuth.', [
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
        ]);

        return $this->repository->createFromGoogle([
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
        ]);
    }
}
