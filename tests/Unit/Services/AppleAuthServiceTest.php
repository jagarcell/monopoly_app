<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Repositories\AppleAuthRepository;
use App\Services\AppleAuthService;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AppleAuthServiceTest extends TestCase
{
    private AppleAuthService $service;
    private MockInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = Mockery::mock(AppleAuthRepository::class);
        $this->service = new AppleAuthService($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock SocialiteUser for Apple with the given attributes.
     */
    private function makeAppleUser(
        string $id = 'apple.user.001',
        string $name = 'John Doe',
        string $email = 'john@privaterelay.appleid.com',
    ): SocialiteUser {
        $appleUser = Mockery::mock(SocialiteUser::class);
        $appleUser->shouldReceive('getId')->andReturn($id);
        $appleUser->shouldReceive('getName')->andReturn($name);
        $appleUser->shouldReceive('getEmail')->andReturn($email);

        return $appleUser;
    }

    public function test_returns_existing_user_found_by_apple_id(): void
    {
        $existingUser = new User([
            'id'       => 1,
            'name'     => 'John Doe',
            'email'    => 'john@privaterelay.appleid.com',
            'apple_id' => 'apple.user.001',
        ]);

        $appleUser = $this->makeAppleUser();

        $this->repository
            ->shouldReceive('findByAppleId')
            ->once()
            ->with('apple.user.001')
            ->andReturn($existingUser);

        $this->repository->shouldNotReceive('findByEmail');
        $this->repository->shouldNotReceive('createFromApple');

        $result = $this->service->findOrCreateUser($appleUser);

        $this->assertSame($existingUser, $result);
    }

    public function test_links_apple_account_when_user_found_by_email(): void
    {
        $existingUser = new User([
            'id'    => 2,
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        ]);

        $linkedUser = new User([
            'id'       => 2,
            'name'     => 'Jane Doe',
            'email'    => 'jane@example.com',
            'apple_id' => 'apple.user.002',
        ]);

        $appleUser = $this->makeAppleUser(
            id: 'apple.user.002',
            name: 'Jane Doe',
            email: 'jane@example.com',
        );

        $this->repository
            ->shouldReceive('findByAppleId')
            ->once()
            ->with('apple.user.002')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with('jane@example.com')
            ->andReturn($existingUser);

        $this->repository
            ->shouldReceive('linkAppleAccount')
            ->once()
            ->with($existingUser, 'apple.user.002')
            ->andReturn($linkedUser);

        $this->repository->shouldNotReceive('createFromApple');

        $result = $this->service->findOrCreateUser($appleUser);

        $this->assertSame($linkedUser, $result);
    }

    public function test_creates_new_user_when_no_existing_account(): void
    {
        $newUser = new User([
            'id'       => 3,
            'name'     => 'New User',
            'email'    => 'newuser@privaterelay.appleid.com',
            'apple_id' => 'apple.user.003',
        ]);

        $appleUser = $this->makeAppleUser(
            id: 'apple.user.003',
            name: 'New User',
            email: 'newuser@privaterelay.appleid.com',
        );

        $this->repository
            ->shouldReceive('findByAppleId')
            ->once()
            ->with('apple.user.003')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with('newuser@privaterelay.appleid.com')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('createFromApple')
            ->once()
            ->with([
                'name'     => 'New User',
                'email'    => 'newuser@privaterelay.appleid.com',
                'apple_id' => 'apple.user.003',
            ])
            ->andReturn($newUser);

        $result = $this->service->findOrCreateUser($appleUser);

        $this->assertSame($newUser, $result);
    }

    public function test_uses_email_prefix_as_name_when_apple_returns_empty_name(): void
    {
        $newUser = new User([
            'id'       => 4,
            'name'     => 'alice',
            'email'    => 'alice@privaterelay.appleid.com',
            'apple_id' => 'apple.user.004',
        ]);

        $appleUser = $this->makeAppleUser(
            id: 'apple.user.004',
            name: '',
            email: 'alice@privaterelay.appleid.com',
        );

        $this->repository
            ->shouldReceive('findByAppleId')
            ->once()
            ->with('apple.user.004')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('findByEmail')
            ->once()
            ->with('alice@privaterelay.appleid.com')
            ->andReturnNull();

        $this->repository
            ->shouldReceive('createFromApple')
            ->once()
            ->with([
                'name'     => 'alice',
                'email'    => 'alice@privaterelay.appleid.com',
                'apple_id' => 'apple.user.004',
            ])
            ->andReturn($newUser);

        $result = $this->service->findOrCreateUser($appleUser);

        $this->assertSame($newUser, $result);
    }
}
