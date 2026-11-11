<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Role;
use Statamic\Facades\User;

beforeEach(function () {
    siteContent();
});

it('publishes discovery documents whose resource is exactly the MCP URL', function () {
    // Regression: Claude rejects protected resource metadata whose `resource` differs from the connector URL.
    $this->getJson('/.well-known/oauth-protected-resource/mcp/content')
        ->assertOk()
        ->assertJsonPath('resource', url('/mcp/content'))
        ->assertJsonPath('scopes_supported', ['mcp:use']);

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
        ->assertJsonPath('token_endpoint', url('/oauth/token'))
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

it('connects Claude end to end with a file user and lists the tools', function () {
    $tokens = connectClaude(editor(['access content mcp', 'view blog entries']));

    // Passport's default would be a year; the brief fixes one hour.
    expect($tokens['expires_in'])->toBeGreaterThan(3500)->toBeLessThanOrEqual(3600);

    $tools = mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result.tools');

    expect(collect($tools)->pluck('name')->all())->toContain('get_overview', 'create_entry', 'update_entry', 'publish_entry');

    // The installed release, not a number hardcoded in the source.
    mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude-ai', 'version' => '1']])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.version', InstalledVersions::getPrettyVersion('el-schneider/statamic-content-mcp'));
});

it('lists every tool in one tools/list page, deletes and localization included', function () {
    // Regression: laravel/mcp pages tools/list at 15, and some clients do not follow the cursor.
    config(['statamic.content-mcp.deletes' => true, 'statamic.system.multisite' => true]);
    Statamic\Facades\Site::setSites([
        'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'de' => ['name' => 'Deutsch', 'url' => 'http://localhost/de/', 'locale' => 'de_DE'],
    ]);
    $tokens = connectClaude(editor(['access content mcp']));
    $registered = (new ReflectionClass(ElSchneider\ContentMcp\Server\ContentMcpServer::class))->getProperty('tools')->getDefaultValue();

    $result = mcp($tokens['access_token'], 'tools/list')->assertOk()->json('result');

    expect($result)->not->toHaveKey('nextCursor')
        ->and(collect($result['tools'])->pluck('name')->all())->toContain('delete_entry', 'localize_entry')
        ->and($result['tools'])->toHaveCount(count($registered));
});

it('completes the flow for clients that ask for client_secret_post or omit the scope', function () {
    // Regression: some Claude clients register with client_secret_post, and a token
    // without the mcp:use scope would be rejected by the endpoint after a "successful" connect.
    $tokens = connectClaude(editor(['access content mcp']), authMethod: 'client_secret_post', scope: null);

    mcp($tokens['access_token'], 'tools/list')->assertOk();
});

it('shows the consent screen with the client, the redirect host and the approving account, unframeable', function () {
    $response = loginThroughCp(authorizeUrl(registerClaude()), editor(['access content mcp']));

    $response->assertOk()
        ->assertSee('Connect Claude to')
        ->assertSee('claude.ai')
        ->assertSee('editor@example.com')
        ->assertSee('read and edit content with your permissions')
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->all('content-security-policy'))->toContain("frame-ancestors 'none'");
});

it('shows a 403 page instead of consent to users without the permission', function () {
    $response = loginThroughCp(authorizeUrl(registerClaude()), editor(['view blog entries']));

    $response->assertForbidden()
        ->assertSee('editor@example.com')
        ->assertDontSee('auth_token')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('rotates refresh tokens and rejects a used one with invalid_grant', function () {
    $tokens = connectClaude(editor(['access content mcp']));
    $refresh = fn (string $token) => $this->post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'refresh_token' => $token,
        'client_id' => $tokens['client_id'],
    ]);

    $rotated = $refresh($tokens['refresh_token'])->assertOk()->json();

    expect($rotated['refresh_token'])->not->toBe($tokens['refresh_token']);
    mcp($rotated['access_token'], 'tools/list')->assertOk();
    // Claude starts a new OAuth flow only when the refresh fails with invalid_grant.
    $refresh($tokens['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

it('answers missing and invalid tokens with 401 pointing to the resource metadata', function (?string $token) {
    mcp($token, 'tools/list')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp/content').'"');
})->with([
    'missing' => [null],
    'invalid' => ['not-a-token'],
]);

it('rejects a revoked token', function () {
    $tokens = connectClaude(editor(['access content mcp']));
    DB::table('oauth_access_tokens')->update(['revoked' => true]);

    mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
});

it('cuts access at once when the permission is removed from the role', function () {
    $tokens = connectClaude(editor(['access content mcp', 'view blog entries']));

    Role::find('editor')->removePermission('access content mcp')->save();

    mcp($tokens['access_token'], 'tools/list')->assertForbidden();
});

it('runs the MCP request as the token user for User::current(), auth() and the Gate', function () {
    $editor = editor(['access content mcp', 'view blog entries', 'edit blog entries']);
    $entry = entry('blog', ['title' => 'Before']);
    $tokens = connectClaude($editor);
    $seen = [];

    // A site hook, as Statamic sites write them.
    Event::listen(EntrySaving::class, function (EntrySaving $event) use (&$seen) {
        $seen = [
            'current' => User::current()?->id(),
            'auth' => auth()->id(),
            'gate' => Gate::allows('edit', $event->entry),
        ];
    });

    mcp($tokens['access_token'], 'tools/call', ['name' => 'update_entry', 'arguments' => ['id' => $entry->id(), 'data' => ['title' => 'After']]])
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect($seen)->toBe(['current' => $editor->id(), 'auth' => $editor->id(), 'gate' => true])
        ->and($entry->fresh()->lastModifiedBy()?->id())->toBe($editor->id());
});

it('stores Statamic user ids in string columns after php artisan migrate', function () {
    // Regression: Passport's bigint user_id columns reject UUIDs on MySQL and Postgres.
    foreach (['oauth_auth_codes', 'oauth_access_tokens', 'oauth_device_codes'] as $table) {
        expect(Schema::getColumnType($table, 'user_id'))->toBe('varchar');
    }
});

it('throttles dynamic client registration', function () {
    // laravel/mcp leaves /oauth/register unthrottled, and every call writes a client row.
    foreach (range(1, 60) as $attempt) {
        $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertCreated();
    }

    $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertTooManyRequests();
});

it('refuses authorization requests without S256 PKCE', function (array $pkce) {
    // league/oauth2-server also accepts `plain`, and a missing method means plain.
    $clientId = registerClaude();
    $query = ['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CLAUDE_CALLBACK, 'state' => 's', ...$pkce];

    $this->get('/oauth/authorize?'.http_build_query($query))
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_request');
})->with([
    'plain' => [['code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'plain']],
    'method omitted' => [['code_challenge' => str_repeat('a', 43)]],
    'no challenge' => [[]],
]);

it('sends prompt=login through the CP login and on to consent', function () {
    // Regression: Passport logs the editor out and redirects to route('login'), which Statamic sites lack.
    $editor = editor(['access content mcp']);
    $url = authorizeUrl(registerClaude());
    loginThroughCp($url, $editor)->assertOk();

    loginThroughCp($url.'&prompt=login', $editor)->assertOk()->assertSee('auth_token');
});

it('answers prompt=none for a guest with login_required instead of a login page', function () {
    $redirect = (string) $this->get(authorizeUrl(registerClaude()).'&prompt=none')->assertRedirect()->headers->get('Location');

    expect($redirect)->toStartWith(CLAUDE_CALLBACK.'?')->toContain('error=login_required');
});

it('lets only Claude register clients by default', function () {
    // laravel/mcp's own default accepts redirect URIs on any domain.
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://claude.com/api/mcp/auth_callback']])->assertCreated();
    $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/callback']])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('leaves redirect domains to a site that configures laravel/mcp itself', function () {
    $published = config_path('mcp.php');
    file_put_contents($published, "<?php return ['redirect_domains' => ['https://evil.example']];");

    try {
        $this->refreshApplication();
        $this->artisan('migrate');

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://evil.example/callback']])->assertCreated();
        $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertStatus(400);
    } finally {
        unlink($published);
    }
});
