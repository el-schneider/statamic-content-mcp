<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use Statamic\Statamic;

it('loads Passport\'s migrations from vendor so php artisan migrate is enough', function () {
    expect(app('migrator')->paths())->toContain(InstalledVersions::getInstallPath('laravel/passport').'/database/migrations');
});

it('leaves Passport\'s migrations to sites that already published them', function () {
    // Regression: running both copies would fail with "table oauth_clients already exists".
    $published = database_path('migrations/2026_01_01_000000_create_oauth_clients_table.php');
    file_put_contents($published, '<?php');

    try {
        $this->refreshApplication();

        expect(app('migrator')->paths())->not->toContain(InstalledVersions::getInstallPath('laravel/passport').'/database/migrations')
            ->and(array_map(realpath(...), app('migrator')->paths()))->toContain(realpath(__DIR__.'/../../database/migrations'));
    } finally {
        unlink($published);
    }
});

it('publishes the Control Panel build when Statamic installs, and loads it from there', function () {
    // Statamic runs these callbacks at the end of statamic:install, which sites run after every Composer install.
    Artisan::command('content-mcp:after-install', function () {
        Statamic::runAfterInstalledCallbacks($this);
    });

    try {
        $this->artisan('content-mcp:after-install')->assertSuccessful();

        expect(public_path('vendor/statamic-content-mcp/build/manifest.json'))->toBeFile();

        $this->refreshApplication();
        $vite = Statamic::availableVites(request())['statamic-content-mcp'];
        $tags = (new Vite)->useHotFile($vite['hotFile'])->useBuildDirectory($vite['buildDirectory'])->withEntryPoints($vite['input'])->toHtml();

        expect($tags)->toContain('/vendor/statamic-content-mcp/build/assets/cp-');
    } finally {
        File::deleteDirectory(public_path('vendor'));
    }
});

it('keeps the Control Panel up while the build is not published', function () {
    // Laravel's Vite throws on a missing manifest, and every CP page renders every addon's build.
    (new ReflectionProperty(Statamic::class, 'vites'))->setValue(null, []);
    $this->refreshApplication();

    expect(Statamic::availableVites(request()))->not->toHaveKey('statamic-content-mcp')
        ->and(ServiceProvider::pathsToPublish(ContentMcpServiceProvider::class, 'statamic-content-mcp'))
        ->toBe([realpath(__DIR__.'/../../resources/dist/build').'/' => public_path('vendor/statamic-content-mcp/build/')]);
});
