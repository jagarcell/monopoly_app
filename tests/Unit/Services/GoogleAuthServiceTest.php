<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Repositories\GoogleAuthRepository;
use App\Services\GoogleAuthService;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GoogleAuthServiceTest extends TestCase
{
    private GoogleAuthService $service;
    private MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(GoogleAuthRepository::class);
        $this->service = new GoogleAuthService($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock SocialiteUser with the given attributes.
     */
    private function makeSocialiteUser(
        string $id = '123456',
        string $name = 'John Doe',
        string $email = 'john@example.com',
        ?string $avatar = 'https://example.com/avatar.jpg',
    ): SocialiteUser {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->shouldReceive('getId')->andReturn($id);
        $socialiteUser->shouldReceive('getName')->andReturn($name);
        $socialiteUser->shouldReceive('getEmail')->andReturn($email);
        $socialiteUser->shouldReceive('getAvatar')->andReturn($avatar);

        return $socialiteUser;
    }

    public function test_returns_existing_user_found_by_google_id(): void
    {
        $existingUser = new User([
            'id' => 1,
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'google_id' => '123456',
        ]);

        $socialiteUser = $this->makeSocialiteUser();

        $this->repository
            ->shouldReceive('findByGoogleId')
            ->once()
            ->with('123456')
            ->andReturn($existingUser);

        $this->repository->shouldNotReceive('findByEmail');
        $this->repository->shouldNotReceive('createFromGoogle');

        $result = $this->service->findOrCreateUser($socialiteUser);

        $this->assertSame($existingUser, $result);
    }

    public function test_links_google_account_when_user_found_by_email(): void
    {
        $existingUser = new User([
            'id' => 2,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $linkedUser = new User([
            'id' => 2,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'google_id' => '789012',
            'avatar' => 'https://example.com/jane.jpg',
        ]);

        $socialiteUser = $this->makeSocialiteUser(
            id: '789012',
            name: 'Jane Doe',
            email: 'jane@example.com',
            avatar: 'https://example.com/jane.jpg',
        );

        $this->repository
            ->shouldReceive('findByGoogleId')
            ->once()
            ->with('789012')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with('jane@example.com')
            ->andReturn($existingUser);

        $this->repository
            ->shouldReceive('linkGoogleAccount')
            ->once()
            ->with($existingUser, '789012', 'https://example.com/jane.jpg')
            ->andReturn($linkedUser);

        $this->repository->shouldNotReceive('createFromGoogle');

        $result = $this->service->findOrCreateUser($socialiteUser);

        $this->assertSame($linkedUser, $result);
        $this->assertEquals('789012', $result->google_id);
    }

    public function test_creates_new_user_when_no_existing_user_found(): void
    {
        $newUser = new User([
            'id' => 3,
            'name' => 'New User',
            'email' => 'new@example.com',
            'google_id' => '555555',
            'avatar' => 'https://example.com/new.jpg',
        ]);

        $socialiteUser = $this->makeSocialiteUser(
            id: '555555',
            name: 'New User',
            email: 'new@example.com',
            avatar: 'https://example.com/new.jpg',
        );

        $this->repository
            ->shouldReceive('findByGoogleId')
            ->once()
            ->with('555555')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with('new@example.com')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('createFromGoogle')
            ->once()
            ->with([
                'name' => 'New User',
                'email' => 'new@example.com',
                'google_id' => '555555',
                'avatar' => 'https://example.com/new.jpg',
            ])
            ->andReturn($newUser);

        $result = $this->service->findOrCreateUser($socialiteUser);

        $this->assertSame($newUser, $result);
    }

    public function test_creates_user_with_null_avatar(): void
    {
        $newUser = new User([
            'id' => 4,
            'name' => 'No Avatar User',
            'email' => 'noavatar@example.com',
            'google_id' => '666666',
            'avatar' => null,
        ]);

        $socialiteUser = $this->makeSocialiteUser(
            id: '666666',
            name: 'No Avatar User',
            email: 'noavatar@example.com',
            avatar: null,
        );

        $this->repository
            ->shouldReceive('findByGoogleId')
            ->once()
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->andReturnNull();

        $this->repository
            ->shouldReceive('createFromGoogle')
            ->once()
            ->with([
                'name' => 'No Avatar User',
                'email' => 'noavatar@example.com',
                'google_id' => '666666',
                'avatar' => null,
            ])
            ->andReturn($newUser);

        $result = $this->service->findOrCreateUser($socialiteUser);

        $this->assertNull($result->avatar);
    }
}
