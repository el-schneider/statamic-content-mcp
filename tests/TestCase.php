<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Statamic\Testing\AddonTestCase;
use ElSchneider\ContentMcp\ContentMcpServiceProvider;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ContentMcpServiceProvider::class;
}
