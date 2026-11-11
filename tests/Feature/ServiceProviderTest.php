<?php

declare(strict_types=1);

use Composer\InstalledVersions;

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

it('loads the committed Control Panel build', function () {
    // Sites run no frontend build: the CP loads resources/dist, published to public/vendor.
    $vite = Statamic\Statamic::availableVites(request())['statamic-content-mcp'] ?? null;
    $manifest = json_decode((string) file_get_contents(__DIR__.'/../../resources/dist/build/manifest.json'), true);

    expect($vite)->toMatchArray(['input' => ['resources/js/cp.ts'], 'buildDirectory' => 'vendor/statamic-content-mcp/build'])
        ->and($manifest['resources/js/cp.ts']['file'])->toStartWith('assets/cp-')
        ->and(file_exists(__DIR__.'/../../resources/dist/build/'.$manifest['resources/js/cp.ts']['file']))->toBeTrue();
});
