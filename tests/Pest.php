<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse as HttpResponse;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Mcp\Server\Tool;
use Statamic\Auth\User as StatamicUser;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Role;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\User;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

// laravel/mcp's TestResponse only offers assertions; tests need the payload too.
TestResponse::macro('data', fn (): ?array => $this->structuredContent());
TestResponse::macro('errorText', fn (): string => implode(' ', $this->errors()));
TestResponse::macro('contentBlocks', fn (): array => $this->response->toArray()['result']['content'] ?? []);

/**
 * A blog (dated, bard with sets) and pages (structured, replicator) collection,
 * a tags taxonomy and a footer global, as most tests need them.
 */
function siteContent(): void
{
    Taxonomy::make('tags')->title('Tags')->save();
    Collection::make('blog')->title('Blog')->dated(true)->routes('/blog/{slug}')->taxonomies(['tags'])->save();
    Collection::make('pages')->title('Pages')->routes('{parent_uri}/{slug}')->structureContents(['root' => false])->save();
    GlobalSet::make('footer')->title('Footer')->save();
}

/**
 * Calls a tool through laravel/mcp's test harness, as the given user.
 *
 * @param  class-string<Tool>  $tool
 * @param  array<string, mixed>  $arguments
 */
function tool(StatamicUser $user, string $tool, array $arguments = []): TestResponse
{
    return ContentMcpServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * @param  list<string>  $permissions
 */
function editor(array $permissions, string $email = 'editor@example.com'): StatamicUser
{
    $role = Str::before($email, '@');
    Role::make($role)->title($role)->permissions($permissions)->save();

    $user = User::make()->email($email)->password('secret')->set('name', 'Edith Editor')->assignRole($role);
    $user->save();

    return $user;
}

/**
 * @param  array<string, mixed>  $data
 */
function entry(string $collection, array $data, bool $published = true, ?string $slug = null): Entry
{
    $entry = Entries::make()->collection($collection)->slug($slug ?? Str::slug((string) ($data['title'] ?? 'entry')))->data($data)->published($published);

    if (Collection::findByHandle($collection)?->dated()) {
        $entry->date('2025-01-15');
    }

    $entry->save();

    return $entry;
}

const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/**
 * Registers Claude as a client, walks the editor through CP login and consent
 * like a browser would, and exchanges the code for tokens like Claude does.
 *
 * @return array{client_id: string, access_token: string, refresh_token: string, expires_in: int}
 */
function connectClaude(StatamicUser $user, string $authMethod = 'none', ?string $scope = 'mcp:use'): array
{
    $clientId = registerClaude($authMethod);
    $verifier = Str::random(64);

    $consent = loginThroughCp(authorizeUrl($clientId, $verifier, $scope), $user)->assertOk();
    preg_match('/name="auth_token" value="([^"]+)"/', (string) $consent->getContent(), $matches);

    $redirect = (string) test()->post('/oauth/authorize', [
        'state' => 'state-123',
        'client_id' => $clientId,
        'auth_token' => $matches[1],
    ])->assertRedirect()->headers->get('Location');

    expect($redirect)->toStartWith(CLAUDE_CALLBACK.'?');
    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $callback);
    expect($callback['state'])->toBe('state-123');

    // Claude is not the browser: from here on there is no session, only the bearer token.
    test()->flushSession();
    app('auth')->forgetGuards();

    return [
        'client_id' => $clientId,
        ...test()->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => CLAUDE_CALLBACK,
            'code' => $callback['code'],
            'code_verifier' => $verifier,
        ])->assertOk()->json(),
    ];
}

function registerClaude(string $authMethod = 'none'): string
{
    return test()->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [CLAUDE_CALLBACK],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => $authMethod,
    ])->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
}

function authorizeUrl(string $clientId, string $verifier = 'verifier', ?string $scope = 'mcp:use'): string
{
    return '/oauth/authorize?'.http_build_query(array_filter([
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
        'code_challenge_method' => 'S256',
        'state' => 'state-123',
        'scope' => $scope,
    ]));
}

/**
 * GET the authorize URL as a guest, follow the redirect to the CP login,
 * log in, and follow the redirect back.
 */
function loginThroughCp(string $authorizeUrl, StatamicUser $user): HttpResponse
{
    $login = (string) test()->get($authorizeUrl)->assertRedirect(cp_route('login'))->headers->get('Location');

    $back = (string) test()->post($login, ['email' => $user->email(), 'password' => 'secret'])
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($back, PHP_URL_QUERY), $backQuery);
    parse_str((string) parse_url($authorizeUrl, PHP_URL_QUERY), $authorizeQuery);
    expect(Str::before($back, '?'))->toBe(url('/oauth/authorize'))
        ->and($backQuery)->toEqualCanonicalizing($authorizeQuery);

    return test()->get($back);
}

/**
 * @param  array<string, mixed>  $params
 */
function mcp(?string $token, string $method, array $params = []): HttpResponse
{
    return test()->withHeaders($token === null ? [] : ['Authorization' => 'Bearer '.$token])
        ->postJson('/mcp/content', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}
