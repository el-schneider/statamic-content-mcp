<?php

declare(strict_types=1);

namespace Tests;

use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use LazilyRefreshDatabase;
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ContentMcpServiceProvider::class;

    /** @var array{private: string, public: string}|null */
    private static ?array $keys = null;

    /**
     * A run killed mid-test (e.g. output piped into `head`) leaves its files behind:
     * RouteCacheTest's cached routes would make every later test boot with them, and
     * AddonTestCase wipes the fake Stache directory only in tearDown. Addon settings
     * saved through the Control Panel land in the skeleton's resources/addons.
     */
    protected function setUp(): void
    {
        foreach (['/bootstrap/cache/routes-v7.php', '/resources/addons/statamic-content-mcp.yaml'] as $leftover) {
            if (file_exists($path = static::applicationBasePath().$leftover)) {
                unlink($path);
            }
        }

        parent::setUp();

        $this->deleteFakeStacheDirectory();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            McpServiceProvider::class,
            PassportServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('statamic.system.blueprints_path', __DIR__.'/__fixtures__/blueprints');
        // What a Statamic site with file users has in config/auth.php.
        $app['config']->set('auth.providers.users', ['driver' => 'statamic']);
        $app['config']->set('passport.private_key', self::keys()['private']);
        $app['config']->set('passport.public_key', self::keys()['public']);
        $app['config']->set('statamic.editions.pro', true);
        // Inside the directory PreventsSavingStacheItemsToDisk wipes after each test.
        $app['config']->set('statamic.users.repositories.file.paths.roles', __DIR__.'/__fixtures__/dev-null/roles.yaml');
    }

    /**
     * @return array{private: string, public: string}
     */
    private static function keys(): array
    {
        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $private);
            self::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($key)['key']];
        }

        return self::$keys;
    }
}
