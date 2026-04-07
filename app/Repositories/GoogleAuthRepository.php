<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class GoogleAuthRepository
{
    /**
     * Find an existing user by their Google ID.
     *
     * @param  string  $googleId  The Google OAuth user ID.
     * @return User|null
     *
     * Logic: Queries the users table for a matching google_id. Returns the
     * Eloquent model when found, or null when no match exists.
     */
    public function findByGoogleId(string $googleId): ?User
    {
        return User::select(['id', 'name', 'email', 'google_id', 'avatar', 'password'])
            ->where('google_id', $googleId)
            ->first();
    }

    /**
     * Find an existing user by their email address.
     *
     * @param  string  $email  The email address to search for.
     * @return User|null
     *
     * Logic: Queries the users table for a matching email. Used as a fallback
     * when no user with the given google_id exists but a user with the same
     * email may already have a local account.
     */
    public function findByEmail(string $email): ?User
    {
        return User::select(['id', 'name', 'email', 'google_id', 'avatar', 'password'])
            ->where('email', $email)
            ->first();
    }

    /**
     * Link a Google account to an existing user.
     *
     * @param  User    $user      The existing user model.
     * @param  string  $googleId  The Google OAuth user ID to link.
     * @param  string|null  $avatar  The Google profile avatar URL.
     * @return User
     *
     * Logic: Updates the user record with the google_id and avatar from Google,
     * linking the OAuth identity to the existing local account.
     */
    public function linkGoogleAccount(User $user, string $googleId, ?string $avatar): User
    {
        $user->update([
            'google_id' => $googleId,
            'avatar' => $avatar,
        ]);

        return $user;
    }

    /**
     * Create a new user from Google OAuth data.
     *
     * @param  array{name: string, email: string, google_id: string, avatar: string|null}  $data  The user data from Google.
     * @return User
     *
     * Logic: Creates a new user record with Google OAuth data. The password is
     * left null since the user authenticates exclusively via Google.
     * email_verified_at is set because Google has already verified the email.
     */
    public function createFromGoogle(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'google_id' => $data['google_id'],
            'avatar' => $data['avatar'],
            'email_verified_at' => now(),
        ]);
    }
}
