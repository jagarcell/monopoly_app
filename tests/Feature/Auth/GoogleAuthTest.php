<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a mock SocialiteUser with the given attributes.
     */
    private function mockSocialiteUser(
        string $id = '123456789',
        string $name = 'Test Google User',
        string $email = 'testgoogle@example.com',
        ?string $avatar = 'https://lh3.googleusercontent.com/photo.jpg',
    ): void {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getAvatar')->andReturn($avatar);

        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($driver);
    }

    public function test_google_redirect_sends_user_to_google(): void
    {
        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('redirect')
            ->andReturn(redirect('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($driver);

        $response = $this->get(route('auth.google'));

        $response->assertRedirect();
    }

    public function test_google_callback_creates_new_user_and_authenticates(): void
    {
        $this->mockSocialiteUser();

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'email' => 'testgoogle@example.com',
            'google_id' => '123456789',
            'name' => 'Test Google User',
        ]);
    }

    public function test_google_callback_links_existing_user_by_email(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
            'google_id' => null,
        ]);

        $this->mockSocialiteUser(
            id: '999888777',
            name: 'Existing User',
            email: 'existing@example.com',
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'google_id' => '999888777',
        ]);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_callback_authenticates_existing_google_user(): void
    {
        User::factory()->create([
            'email' => 'returning@example.com',
            'google_id' => '111222333',
        ]);

        $this->mockSocialiteUser(
            id: '111222333',
            name: 'Returning User',
            email: 'returning@example.com',
        );

        $response = $this->get(route('auth.google.callback'));

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_google_callback_handles_socialite_exception(): void
    {
        $driver = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
        $driver->shouldReceive('user')
            ->andThrow(new \Exception('Invalid token'));

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($driver);

        $response = $this->get(route('auth.google.callback'));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_google_callback_sets_email_verified_at_for_new_user(): void
    {
        $this->mockSocialiteUser();

        $this->get(route('auth.google.callback'));

        $user = User::where('email', 'testgoogle@example.com')->first();

        $this->assertNotNull($user->email_verified_at);
    }

    public function test_google_routes_are_accessible_only_to_guests(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('auth.google'));

        $response->assertRedirect(route('dashboard', absolute: false));
    }
}
