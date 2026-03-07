<?php

declare(strict_types=1);

namespace Tests;

use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use ReflectionClass;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ContentMcpServiceProvider::class;

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            McpServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $reflector = new ReflectionClass($this->addonServiceProvider);
        $directory = dirname($reflector->getFileName());

        $app['config']->set(
            'statamic.system.blueprints_path',
            $directory.'/../tests/__fixtures__/blueprints'
        );
    }
}
