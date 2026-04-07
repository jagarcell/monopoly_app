<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\AppleAuthRepository;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\User as SocialiteUser;

class AppleAuthService
{
    /**
     * @param  AppleAuthRepository  $repository
     */
    public function __construct(
        private readonly AppleAuthRepository $repository,
    ) {}

    /**
     * Find or create a user from an Apple Sign In response.
     *
     * @param  SocialiteUser  $appleUser  The user data returned by Socialite for Apple.
     * @return User
     *
     * Logic: Attempts to find an existing user by apple_id first. If not found,
     * falls back to finding by email to handle users who registered locally before
     * using Apple Sign In. Links the Apple account if found by email. Creates a
     * brand-new user if neither lookup succeeds. Apple may return an empty name
     * on subsequent logins, so we fall back to the email prefix in that case.
     */
    public function findOrCreateUser(SocialiteUser $appleUser): User
    {
        $user = $this->repository->findByAppleId($appleUser->getId());

        if ($user) {
            return $user;
        }

        $email = $appleUser->getEmail();

        $user = $this->repository->findByEmail($email);

        if ($user) {
            Log::info('Linking Apple account to existing user.', [
                'user_id'  => $user->id,
                'apple_id' => $appleUser->getId(),
            ]);

            return $this->repository->linkAppleAccount($user, $appleUser->getId());
        }

        $name = $appleUser->getName();

        if (empty($name)) {
            $name = strstr($email, '@', before_needle: true);
        }

        Log::info('Creating new user from Apple Sign In.', [
            'email'    => $email,
            'apple_id' => $appleUser->getId(),
        ]);

        return $this->repository->createFromApple([
            'name'     => $name,
            'email'    => $email,
            'apple_id' => $appleUser->getId(),
        ]);
    }
}
