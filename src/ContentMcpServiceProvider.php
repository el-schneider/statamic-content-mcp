<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Carbon\CarbonInterval;
use Composer\InstalledVersions;
use ElSchneider\ContentMcp\Http\Middleware\EnsureEditorCanConsent;
use ElSchneider\ContentMcp\Http\ResolveBearerTokenUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Passport;
use Statamic\Facades\Permission;
use Statamic\Facades\Site;
use Statamic\Providers\AddonServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class ContentMcpServiceProvider extends AddonServiceProvider
{
    public const GUARD = 'content-mcp';

    public const PERMISSION = 'access content mcp';

    protected $viewNamespace = 'content-mcp';

    // Our strings live in JSON files, which Statamic's namespaced loader doesn't read.
    protected $translations = false;

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic/content-mcp.php', 'statamic.content-mcp');

        // Defined in memory so the site's config/auth.php stays untouched.
        config(['auth.guards.'.self::GUARD => ['driver' => self::GUARD]]);

        // Passport logs editors in through Statamic's CP guard. Deferred to booting because
        // Statamic merges its config in its own register(), which may run after ours.
        $this->app->booting(function (): void {
            config(['passport.guard' => config('statamic.users.guards.cp')]);

            // laravel/mcp lets clients on any domain register. A site that publishes
            // config/mcp.php decides for itself; checked by file because config:cache
            // would otherwise hide whose value is in effect.
            if (! file_exists(config_path('mcp.php'))) {
                config(['mcp.redirect_domains' => config('statamic.content-mcp.redirect_domains')]);
            }
        });
    }

    public function boot(): void
    {
        parent::boot();

        Auth::viaRequest(self::GUARD, fn (Request $request) => app(ResolveBearerTokenUser::class)($request));

        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(90));
        // A client that omits `scope` would otherwise get a token our endpoint rejects.
        Passport::setDefaultScope([Registrar::OAUTH_SCOPE]);
        Passport::authorizationView(fn (array $parameters): Response => response()->view('content-mcp::authorize', [
            ...$parameters,
            'siteName' => Site::default()->name(),
            'redirectHost' => parse_url($parameters['request']->query('redirect_uri') ?? $parameters['client']->redirect_uris[0], PHP_URL_HOST),
        ]));

        // Cached routes already carry the middleware from when they were cached.
        $this->app->booted(function (): void {
            if (! $this->app->routesAreCached()) {
                Route::getRoutes()->getByName('passport.authorizations.authorize')?->middleware(EnsureEditorCanConsent::class);
            }
        });

        $this->loadRoutesFrom(__DIR__.'/../routes/mcp.php');
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');

        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(array_filter([$this->passportMigrations(), __DIR__.'/../database/migrations']));

            $this->publishes([
                __DIR__.'/../config/statamic/content-mcp.php' => config_path('statamic/content-mcp.php'),
            ], 'content-mcp-config');
        }
    }

    public function bootAddon(): void
    {
        Permission::extend(fn () => Permission::register(self::PERMISSION)->label('Use AI assistants through Content MCP'));
    }

    /**
     * Passport 13 only publishes its migrations. Loading them from vendor makes
     * `php artisan migrate` sufficient; a site that already published them keeps its copies.
     */
    private function passportMigrations(): ?string
    {
        if (glob(database_path('migrations/*_create_oauth_clients_table.php')) !== []) {
            return null;
        }

        return InstalledVersions::getInstallPath('laravel/passport').'/database/migrations';
    }
}
