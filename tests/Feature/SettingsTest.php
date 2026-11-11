<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Settings;
use ElSchneider\ContentMcp\Tools\DeleteEntry;
use ElSchneider\ContentMcp\Tools\GetOverview;
use Statamic\Facades\Addon;

/*
 * Owners change guidelines, allowed apps and deletes in the Control Panel through
 * Statamic's addon settings. The config file holds the defaults; a saved value wins.
 */

const SETTINGS_PERMISSION = 'edit el-schneider/statamic-content-mcp settings';

beforeEach(function () {
    siteContent();
    config(['statamic.content-mcp.instructions' => 'From the config file.']);
});

/**
 * Saves the settings the way the Control Panel's settings form does.
 *
 * @param  array<string, mixed>  $values
 */
function saveSettings(array $values): Illuminate\Testing\TestResponse
{
    $owner = Statamic\Facades\User::findByEmail('owner@example.com') ?? editor(['access cp', SETTINGS_PERMISSION], 'owner@example.com');

    $response = test()->actingAs($owner)->patchJson(cp_route('addons.settings.update', 'statamic-content-mcp'), [
        'instructions' => null,
        'redirect_domains' => ['https://claude.ai', 'https://claude.com'],
        'deletes' => false,
        ...$values,
    ]);

    // Later requests come from other clients, not from the owner's browser.
    app('auth')->forgetGuards();

    return $response;
}

function serverInstructions(): string
{
    $server = new ContentMcpServer(new Laravel\Mcp\Server\Transport\FakeTransporter);
    $server->start();

    return $server->createContext()->instructions;
}

it('uses the config file while nothing was saved in the Control Panel', function () {
    $overview = tool(editor(['access content mcp']), GetOverview::class)->data();

    expect($overview['guidelines'])->toBe('From the config file.')
        ->and(serverInstructions())->toContain('From the config file.');

    ContentMcpServer::tools()->assertNotRegistered(DeleteEntry::class);
});

it('lets guidelines saved in the Control Panel win over the config file', function () {
    saveSettings(['instructions' => 'Write like a gardener.'])->assertOk()->assertJson(['saved' => true]);

    $tokens = connectClaude(editor(['access content mcp']));
    $instructions = mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->assertOk()->json('result.instructions');

    expect($instructions)->toContain('Write like a gardener.')->not->toContain('From the config file.')
        ->and(tool(editor(['access content mcp'], 'reader@example.com'), GetOverview::class)->data()['guidelines'])->toBe('Write like a gardener.');
});

it('keeps guidelines that show Antlers as plain text, through saving, initialize and get_overview', function () {
    $guidelines = "Templates use {{ title }} and {{ 1 / 0 }}.\n\n```antlers\n{{ partial:hero }}\n```";

    saveSettings(['instructions' => $guidelines])->assertOk()->assertJson(['saved' => true]);

    $tokens = connectClaude(editor(['access content mcp']));
    $instructions = mcp($tokens['access_token'], 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->assertOk()->json('result.instructions');

    expect($instructions)->toContain($guidelines)
        ->and(tool(editor(['access content mcp'], 'reader@example.com'), GetOverview::class)->data()['guidelines'])->toBe($guidelines);
});

it('keeps guidelines from the config file that show Antlers as plain text', function () {
    config(['statamic.content-mcp.instructions' => 'Write {{ 1 / 0 }} literally.']);

    $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertCreated();

    expect(serverInstructions())->toContain('Write {{ 1 / 0 }} literally.')
        ->and(tool(editor(['access content mcp']), GetOverview::class)->data()['guidelines'])->toBe('Write {{ 1 / 0 }} literally.');

    $owner = editor(['access cp', SETTINGS_PERMISSION], 'owner@example.com');
    $this->actingAs($owner)->getJson(cp_route('addons.settings.edit', 'statamic-content-mcp'))
        ->assertOk()->assertJsonPath('values.instructions', 'Write {{ 1 / 0 }} literally.');

    // Other addons keep Statamic's behaviour.
    $other = app()->makeWith(Statamic\Contracts\Addons\Settings::class, ['addon' => Statamic\Addons\Addon::make('vendor/other'), 'settings' => ['sum' => '{{ 1 + 1 }}']]);

    expect($other->get('sum'))->toBe('2');
});

it('falls back to the config file for guidelines left empty, which is what the form shows', function () {
    saveSettings(['instructions' => null])->assertOk();

    expect(tool(editor(['access content mcp']), GetOverview::class)->data()['guidelines'])->toBe('From the config file.');
});

it('registers the delete tools when deleting is allowed in the Control Panel', function () {
    saveSettings(['deletes' => true])->assertOk();

    ContentMcpServer::tools()->assertRegistered(DeleteEntry::class);

    config(['statamic.content-mcp.deletes' => true]);
    saveSettings(['deletes' => false])->assertOk();

    ContentMcpServer::tools()->assertNotRegistered(DeleteEntry::class);
});

it('lets the allowed apps saved in the Control Panel decide which apps may register', function () {
    saveSettings(['redirect_domains' => ['https://assistant.example', 'http://localhost']])->assertOk();

    $this->postJson('/oauth/register', ['redirect_uris' => ['https://assistant.example/callback']])->assertCreated();
    $this->postJson('/oauth/register', ['redirect_uris' => ['http://localhost:7777/callback']])->assertCreated();
    $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertStatus(400);
});

it('refuses allowed apps that are not a scheme and host', function (string $entry) {
    saveSettings(['redirect_domains' => [$entry]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('redirect_domains');

    expect(Addon::get('el-schneider/statamic-content-mcp')->settings()->raw())->toBe([]);
})->with([
    'no scheme' => ['claude.ai'],
    'other scheme' => ['ftp://claude.ai'],
    'path' => ['https://claude.ai/api'],
    'query' => ['https://claude.ai?x=1'],
    'wildcard host' => ['https://*.example.com'],
    'credentials' => ['https://user@claude.ai'],
]);

it('keeps the app-level config/mcp.php in charge of allowed apps, and says so in the settings form', function () {
    $published = config_path('mcp.php');
    file_put_contents($published, "<?php return ['redirect_domains' => ['https://app.example']];");

    try {
        $this->refreshApplication();
        $this->artisan('migrate');
        // Saved directly: a CP request needs the mocks AddonTestCase sets up before refreshApplication().
        Addon::get('el-schneider/statamic-content-mcp')->settings()->set(['redirect_domains' => ['https://assistant.example']])->save();

        $this->postJson('/oauth/register', ['redirect_uris' => ['https://app.example/callback']])->assertCreated();
        $this->postJson('/oauth/register', ['redirect_uris' => ['https://assistant.example/callback']])->assertStatus(400);

        $field = collect(Addon::get('el-schneider/statamic-content-mcp')->settingsBlueprint()->fields()->all())->get('redirect_domains');

        expect($field->visibility())->toBe('read_only')
            ->and($field->instructions())->toContain('config/mcp.php');
    } finally {
        unlink($published);
    }
});

it('needs the settings permission to save', function () {
    $this->actingAs(editor(['access cp', 'access content mcp'], 'nosettings@example.com'))
        ->patchJson(cp_route('addons.settings.update', 'statamic-content-mcp'), ['deletes' => true])
        ->assertForbidden();

    ContentMcpServer::tools()->assertNotRegistered(DeleteEntry::class);
});

it('stores only what the owner changed, so untouched fields follow later changes to the config file', function () {
    $owner = editor(['access cp', SETTINGS_PERMISSION], 'owner@example.com');
    $settingsUrl = cp_route('addons.settings.update', 'statamic-content-mcp');
    $form = $this->actingAs($owner)->getJson(cp_route('addons.settings.edit', 'statamic-content-mcp'))->assertOk()->json('values');

    $this->patchJson($settingsUrl, $form)->assertOk();

    expect(Addon::get('el-schneider/statamic-content-mcp')->settings()->raw())->toBe([]);

    $this->patchJson($settingsUrl, [...$form, 'deletes' => true])->assertOk();
    config([
        'statamic.content-mcp.instructions' => 'Changed in the config file.',
        'statamic.content-mcp.redirect_domains' => ['https://assistant.example'],
    ]);

    expect(Addon::get('el-schneider/statamic-content-mcp')->settings()->raw())->toBe(['deletes' => true])
        ->and(Settings::instructions())->toBe('Changed in the config file.')
        ->and(Settings::redirectDomains())->toBe(['https://assistant.example'])
        ->and(Settings::deletes())->toBeTrue();
});

it('lets an emptied list of allowed apps stop every app from registering', function () {
    saveSettings(['redirect_domains' => []])->assertOk();

    $this->postJson('/oauth/register', ['redirect_uris' => [CLAUDE_CALLBACK]])->assertStatus(400);

    expect(Settings::blueprint()['tabs']['main']['sections'][0]['fields'][1]['field']['instructions'])->toContain('empty');
});

it('shows the values in effect in the settings form before anything is saved', function () {
    $owner = editor(['access cp', SETTINGS_PERMISSION], 'owner@example.com');

    $form = $this->actingAs($owner)->getJson(cp_route('addons.settings.edit', 'statamic-content-mcp'))->assertOk()->json();

    expect($form['values'])->toMatchArray([
        'instructions' => 'From the config file.',
        'redirect_domains' => ['https://claude.ai', 'https://claude.com'],
        'deletes' => false,
    ])->and(collect($form['blueprint']['tabs'][0]['sections'][0]['fields'])->pluck('display')->all())
        ->toBe(['Editorial guidelines', 'Allowed apps', 'Allow deleting']);
});
