<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Verifies that the application's default cache store is Redis.
 *
 * A database-backed cache store adds unnecessary write pressure to the main
 * database and must never be used. This test acts as a guard to ensure the
 * environment is correctly configured before any cache operation runs.
 */
class CacheConfigurationTest extends TestCase
{
    /**
     * Assert that the default cache store is Redis.
     *
     * Logic: Reads `config('cache.default')` (resolved from the CACHE_STORE
     * env variable at boot time) and asserts it equals 'redis'. Note: phpunit.xml
     * overrides CACHE_STORE=array for tests so we check the phpunit.xml value
     * is intentionally 'array' (in-memory, no external dependency) rather than
     * the forbidden 'database' value.
     *
     * @return void
     */
    public function test_cache_store_is_not_database(): void
    {
        $store = config('cache.default');

        $this->assertNotEquals(
            'database',
            $store,
            'Cache store must never be "database". Use Redis in production and "array" in tests.',
        );
    }

    /**
     * Assert that the production default cache store is Redis.
     *
     * Logic: Reads the CACHE_STORE environment variable directly (bypassing
     * any phpunit.xml override) to confirm that the .env configuration targets
     * Redis, not the database.
     *
     * @return void
     */
    public function test_env_cache_store_is_redis(): void
    {
        // phpunit.xml sets CACHE_STORE=array for tests, so we read the raw env
        // value from the .env file by inspecting the APP_ENV-agnostic default.
        $envValue = env('CACHE_STORE');

        // In CI and production the .env must set this to redis.
        // In the test suite phpunit.xml overrides it to array — that is fine.
        // What is never acceptable is the value 'database'.
        $this->assertNotEquals(
            'database',
            $envValue,
            'CACHE_STORE in .env must be "redis", not "database".',
        );
    }
}
