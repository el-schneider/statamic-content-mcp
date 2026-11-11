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
use Statamic\CP\Navigation\Nav as Navigation;
use Statamic\Facades\Addon;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Site;
use Statamic\Providers\AddonServiceProvider;
use Symfony\Component\HttpFoundation\Response;

final class ContentMcpServiceProvider extends AddonServiceProvider
{
    public const GUARD = 'content-mcp';

    public const PERMISSION = 'access content mcp';

    public const MANAGE_PERMISSION = 'manage content mcp connections';

    protected $viewNamespace = 'content-mcp';

    // Our strings live in JSON files, which Statamic's namespaced loader doesn't read.
    protected $translations = false;

    /**
     * The Content MCP area under Tools; each page shows only to those who may open it.
     */
    public static function navigation(Navigation $nav): void
    {
        $nav->findOrCreate('Tools', 'Content MCP')
            ->route('content-mcp.connect')
            ->icon('ai-chat-spark')
            ->can(self::PERMISSION)
            ->children([
                $nav->item(__('Connect'))->route('content-mcp.connect')->can(self::PERMISSION),
                $nav->item(__('Connections'))->route('content-mcp.connections')->can(self::MANAGE_PERMISSION),
                $nav->item(__('Settings'))->route('content-mcp.settings')->can('editSettings', [Addon::get(Settings::PACKAGE)]),
            ]);
    }

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
        Permission::extend(function (): void {
            Permission::group('content_mcp', 'Content MCP', function (): void {
                Permission::register(self::PERMISSION)->label('Use AI assistants through Content MCP');
                Permission::register(self::MANAGE_PERMISSION)->label('See and disconnect every user\'s AI app connections');
            });
        });

        Nav::extend(self::navigation(...));

        // Built into resources/dist and committed; sites run no frontend build. Registered
        // here, not with $vite, whose docblock in older Statamic versions allows no options.
        $this->registerVite(['input' => ['resources/js/cp.ts'], 'publicDirectory' => 'resources/dist']);

        // A closure, so labels follow the CP user's language and defaults the config file.
        $this->registerSettingsBlueprint(fn (): array => Settings::blueprint());
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
