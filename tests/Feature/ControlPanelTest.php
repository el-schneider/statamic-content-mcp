<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;

/*
 * The Content MCP area of the Control Panel, through HTTP as real CP users:
 * Connect for editors, Connections for those who manage them, Settings for owners.
 */

beforeEach(function () {
    siteContent();
});

function cpUser(array $permissions, string $email): StatamicUser
{
    return editor(['access cp', ...$permissions], $email);
}

/**
 * Signs in as another CP user, as if in another browser.
 */
function signIn(StatamicUser $user): Tests\TestCase
{
    test()->flushSession();
    app('auth')->forgetGuards();

    return test()->actingAs($user);
}

/**
 * @return array{client_id: string, access_token: string, refresh_token: string}
 */
function connectedApp(StatamicUser $user): array
{
    $tokens = connectClaude($user);
    test()->flushSession();
    app('auth')->forgetGuards();

    return $tokens;
}

function refreshWith(array $tokens): Illuminate\Testing\TestResponse
{
    return test()->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $tokens['client_id']]);
}

it('hides the area from users without the permission', function () {
    $plain = cpUser([], 'plain@example.com');

    foreach (['connect', 'connections', 'settings'] as $page) {
        signIn($plain)->getJson(cp_route("content-mcp.{$page}"))->assertForbidden();
    }

    // A page load gets the CP's own answer: back to where the user came from, with an error.
    signIn($plain)->get(cp_route('content-mcp.connect'))->assertRedirect()->assertSessionHas('error');
});

it('shows editors the endpoint, whether deleting is on, and only their own connections', function () {
    $editor = cpUser(['access content mcp'], 'editor@example.com');
    $other = cpUser(['access content mcp'], 'other@example.com');
    $mine = connectedApp($editor);
    connectedApp($other);

    signIn($editor)->get(cp_route('content-mcp.connect'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('content-mcp::Connect')
            ->where('endpoint', url('/mcp/content'))
            ->where('deletes', false)
            ->has('connections', 1)
            ->where('connections.0.client_id', $mine['client_id'])
            ->where('connections.0.user.email', 'editor@example.com')
            ->where('connections.0.app', 'Claude')
            ->where('connections.0.redirect_host', 'claude.ai')
            ->where('connections.0.state', 'active')
            ->where('tabs', [['title' => 'Connect', 'url' => cp_route('content-mcp.connect'), 'active' => true]]));
});

it('shows every connection to users who manage them, including those of deleted users', function () {
    $manager = cpUser(['access content mcp', 'manage content mcp connections'], 'manager@example.com');
    $gone = cpUser(['access content mcp'], 'gone@example.com');
    connectedApp(cpUser(['access content mcp'], 'editor@example.com'));
    connectedApp($gone);
    User::find($gone->id())->delete();

    signIn($manager)->get(cp_route('content-mcp.connections'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('content-mcp::Connections')
            ->has('connections', 2)
            ->where('connections', fn ($connections) => collect($connections)->pluck('user.email')->sort()->values()->all() === [null, 'editor@example.com'])
            ->where('tabs.1.title', 'Connections'));

    signIn(cpUser(['access content mcp'], 'editor2@example.com'))->getJson(cp_route('content-mcp.connections'))->assertForbidden();
});

it('lets editors disconnect their own app, which stops its bearer and refresh tokens', function () {
    $editor = cpUser(['access content mcp'], 'editor@example.com');
    $tokens = connectedApp($editor);

    signIn($editor)->from(cp_route('content-mcp.connect'))
        ->delete(cp_route('content-mcp.connections.destroy', ['client' => $tokens['client_id'], 'user' => $editor->id()]))
        ->assertRedirect(cp_route('content-mcp.connect'))
        ->assertSessionHas('success');
    app('auth')->forgetGuards();

    mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
    refreshWith($tokens)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    signIn($editor)->get(cp_route('content-mcp.connect'))->assertInertia(fn (AssertableInertia $page) => $page->has('connections', 0));
});

it('lets only users who manage connections disconnect someone else', function () {
    $owner = cpUser(['access content mcp'], 'owner@example.com');
    $tokens = connectedApp($owner);
    $url = cp_route('content-mcp.connections.destroy', ['client' => $tokens['client_id'], 'user' => $owner->id()]);

    signIn(cpUser(['access content mcp'], 'other@example.com'))->deleteJson($url)->assertForbidden();
    app('auth')->forgetGuards();
    mcp($tokens['access_token'], 'tools/list')->assertOk();

    signIn(cpUser(['manage content mcp connections'], 'manager@example.com'))->delete($url)->assertRedirect()->assertSessionHas('success');
    app('auth')->forgetGuards();
    mcp($tokens['access_token'], 'tools/list')->assertUnauthorized();
});

it('lets managers disconnect a deleted user\'s app and answers 404 for nothing to disconnect', function () {
    $gone = cpUser(['access content mcp'], 'gone@example.com');
    $tokens = connectedApp($gone);
    User::find($gone->id())->delete();
    $manager = cpUser(['manage content mcp connections'], 'manager@example.com');

    signIn($manager)->delete(cp_route('content-mcp.connections.destroy', ['client' => $tokens['client_id'], 'user' => $gone->id()]))->assertRedirect();
    signIn($manager)->delete(cp_route('content-mcp.connections.destroy', ['client' => 'nope', 'user' => $gone->id()]))->assertNotFound();

    refreshWith($tokens)->assertStatus(400);
});

it('renders the settings form inside the area, saving through Statamic\'s own settings route', function () {
    $owner = cpUser(['access content mcp', 'edit el-schneider/statamic-content-mcp settings'], 'owner@example.com');

    signIn($owner)->get(cp_route('content-mcp.settings'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('content-mcp::Settings')
            ->where('submitUrl', cp_route('addons.settings.update', 'statamic-content-mcp'))
            ->where('values.redirect_domains', ['https://claude.ai', 'https://claude.com'])
            ->where('blueprint.tabs.0.sections.0.fields.0.handle', 'instructions')
            ->where('tabs', fn ($tabs) => collect($tabs)->pluck('title')->all() === ['Connect', 'Settings']));
});
