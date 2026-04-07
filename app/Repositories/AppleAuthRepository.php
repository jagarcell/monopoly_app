<?php

namespace App\Repositories;

use App\Models\User;

class AppleAuthRepository
{
    /**
     * Find an existing user by their Apple ID.
     *
     * @param  string  $appleId  The Apple Sign In user identifier.
     * @return User|null
     *
     * Logic: Queries the users table for a matching apple_id. Returns the
     * Eloquent model when found, or null when no match exists.
     */
    public function findByAppleId(string $appleId): ?User
    {
        return User::select(['id', 'name', 'email', 'apple_id', 'avatar', 'password'])
            ->where('apple_id', $appleId)
            ->first();
    }

    /**
     * Find an existing user by their email address.
     *
     * @param  string  $email  The email address to search for.
     * @return User|null
     *
     * Logic: Queries the users table for a matching email. Used as a fallback
     * when no user with the given apple_id exists but a local account was
     * created with the same email address previously.
     */
    public function findByEmail(string $email): ?User
    {
        return User::select(['id', 'name', 'email', 'apple_id', 'avatar', 'password'])
            ->where('email', $email)
            ->first();
    }

    /**
     * Link an Apple account to an existing user.
     *
     * @param  User    $user     The existing user model.
     * @param  string  $appleId  The Apple Sign In identifier to link.
     * @return User
     *
     * Logic: Updates the user record with the apple_id, linking the Apple identity
     * to the existing local account. Apple does not provide an avatar URL, so the
     * existing avatar is preserved.
     */
    public function linkAppleAccount(User $user, string $appleId): User
    {
        $user->update([
            'apple_id' => $appleId,
        ]);

        return $user;
    }

    /**
     * Create a new user from Apple Sign In data.
     *
     * @param  array{name: string, email: string, apple_id: string}  $data  The user data from Apple.
     * @return User
     *
     * Logic: Creates a new user record with Apple Sign In data. The password is
     * left null since the user authenticates exclusively via Apple. email_verified_at
     * is set because Apple has already verified the email address.
     */
    public function createFromApple(array $data): User
    {
        return User::create([
            'name'              => $data['name'],
            'email'             => $data['email'],
            'apple_id'          => $data['apple_id'],
            'email_verified_at' => now(),
        ]);
    }
}
