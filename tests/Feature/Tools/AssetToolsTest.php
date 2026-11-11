<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Content\RemoteFile;
use ElSchneider\ContentMcp\Tools\GetAsset;
use ElSchneider\ContentMcp\Tools\GetBlueprint;
use ElSchneider\ContentMcp\Tools\UpdateAsset;
use ElSchneider\ContentMcp\Tools\UploadAsset;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Statamic\Events\AssetSaving;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

beforeEach(function () {
    Storage::fake('assets');
    AssetContainer::make('assets')->title('Assets')->disk('assets')->save();
    $this->editor = editor(['access content mcp', 'view assets assets', 'edit assets assets', 'upload assets assets']);
    $fake = UploadedFile::fake()->image('photo.png', 2400, 1200);
    $this->png = (string) file_get_contents($fake->getPathname());
});

/**
 * Downloads go through a fake network: `example.com` and `cdn.example.com` are public,
 * every other name resolves to whatever the test maps it to.
 *
 * @param  list<HttpResponse>  $responses
 * @param  array<string, list<string>>  $dns
 */
function fakeNetwork(array $responses, array $dns = []): MockHandler
{
    $mock = new MockHandler($responses);
    $dns = ['example.com' => ['93.184.216.34'], 'cdn.example.com' => ['2606:2800:220:1::1'], ...$dns];
    app()->instance(RemoteFile::class, new RemoteFile(fn (string $host): array => $dns[$host] ?? [], $mock));

    return $mock;
}

function uploadErrors(Laravel\Mcp\Server\Testing\TestResponse $response): array
{
    return json_decode($response->errorText(), true)['errors'] ?? [];
}

it('returns an image asset with its data and a downscaled rendition to look at', function () {
    Storage::disk('assets')->put('photos/wide.png', $this->png);
    Asset::find('assets::photos/wide.png')->set('alt', 'A wide photo')->save();

    $response = tool($this->editor, GetAsset::class, ['id' => 'assets::photos/wide.png'])->assertHasNoErrors();
    $image = collect($response->contentBlocks())->firstWhere('type', 'image');
    [$width, $height] = getimagesizefromstring(base64_decode($image['data']));

    expect($response->data())->toMatchArray(['id' => 'assets::photos/wide.png', 'width' => 2400, 'height' => 1200, 'data' => ['alt' => 'A wide photo']])
        ->and($image['mimeType'])->toBe('image/jpeg')
        ->and([$width, $height])->toBe([1024, 512]);
});

it('returns only meta for files that are not images, and hides assets the editor may not view', function () {
    Storage::disk('assets')->put('docs/menu.pdf', '%PDF-1.4');

    $response = tool($this->editor, GetAsset::class, ['id' => 'assets::docs/menu.pdf'])->assertHasNoErrors();

    expect(collect($response->contentBlocks())->pluck('type')->all())->toBe(['text']);
    tool(editor(['access content mcp'], 'other@example.com'), GetAsset::class, ['id' => 'assets::docs/menu.pdf'])
        ->assertHasErrors(['Asset [assets::docs/menu.pdf] not found.']);
});

it('updates asset data through the blueprint, with its validation and the edit permission', function () {
    Storage::disk('assets')->put('photo.png', $this->png);

    tool($this->editor, UpdateAsset::class, ['id' => 'assets::photo.png', 'data' => ['alt' => 'Two chairs']])->assertHasNoErrors();
    $tooLong = uploadErrors(tool($this->editor, UpdateAsset::class, ['id' => 'assets::photo.png', 'data' => ['credit' => str_repeat('x', 30)]]));
    tool(editor(['access content mcp', 'view assets assets'], 'viewer@example.com'), UpdateAsset::class, ['id' => 'assets::photo.png', 'data' => ['alt' => 'x']])
        ->assertHasErrors(['You may not edit this asset.']);

    expect(Asset::find('assets::photo.png')->get('alt'))->toBe('Two chairs')
        ->and($tooLong)->toHaveKey('credit');
});

it('uploads a file from a URL through the connection it checked', function () {
    $mock = fakeNetwork([new HttpResponse(200, ['Content-Type' => 'image/png'], $this->png)]);

    $uploader = editor(['access content mcp', 'view assets assets', 'upload assets assets', 'edit assets folders'], 'uploader@example.com');

    $data = tool($uploader, UploadAsset::class, [
        'container' => 'assets',
        'url' => 'https://example.com/media/Summer%20Fair.png?size=large',
        'folder' => 'events',
        'data' => ['alt' => 'Stalls on the square'],
    ])->assertHasNoErrors()->data();

    expect($data)->toMatchArray(['id' => 'assets::events/summer-fair.png', 'mime_type' => 'image/png', 'data' => ['alt' => 'Stalls on the square']])
        ->and(Storage::disk('assets')->get('events/summer-fair.png'))->toBe($this->png)
        // DNS rebinding: curl must connect to the address that was checked.
        ->and($mock->getLastOptions()['curl'][CURLOPT_RESOLVE])->toBe(['example.com:443:93.184.216.34'])
        ->and($mock->getLastOptions()['allow_redirects'])->toBeFalse();
});

it('refuses URLs that reach private, loopback, link-local or reserved addresses', function (string $url, array $dns) {
    $mock = fakeNetwork([new HttpResponse(200, [], 'secret')], $dns);

    $errors = uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => $url, 'filename' => 'x.txt']));

    expect($errors['url'][0])->toContain('not a public address')
        ->and($mock->count())->toBe(1)
        ->and(Storage::disk('assets')->allFiles())->toBe([]);
})->with([
    'loopback' => ['http://127.0.0.1/x', []],
    'loopback by name' => ['http://localhost.test/x', ['localhost.test' => ['127.0.0.1']]],
    'private 10/8' => ['http://intranet.test/x', ['intranet.test' => ['10.1.2.3']]],
    'private 172.16/12' => ['http://intranet.test/x', ['intranet.test' => ['172.20.0.1']]],
    'private 192.168/16' => ['http://intranet.test/x', ['intranet.test' => ['192.168.1.1']]],
    'link-local metadata' => ['http://169.254.169.254/latest/meta-data', []],
    'CGNAT' => ['http://carrier.test/x', ['carrier.test' => ['100.64.0.1']]],
    'this network' => ['http://0.0.0.0/x', []],
    'multicast' => ['http://224.0.0.1/x', []],
    'reserved' => ['http://240.0.0.1/x', []],
    'broadcast' => ['http://255.255.255.255/x', []],
    'IPv6 loopback' => ['http://[::1]/x', []],
    'IPv6 unique local' => ['http://intranet.test/x', ['intranet.test' => ['fd12:3456::1']]],
    'IPv6 link-local' => ['http://[fe80::1]/x', []],
    'IPv6 multicast' => ['http://[ff02::1]/x', []],
    'IPv4-mapped IPv6' => ['http://[::ffff:10.0.0.1]/x', []],
    'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/x', []],
    'NAT64' => ['http://[64:ff9b::a00:1]/x', []],
    'one private address among public ones' => ['http://mixed.test/x', ['mixed.test' => ['93.184.216.34', '10.0.0.1']]],
]);

it('refuses URLs without a public http(s) target', function (string $url, string $message) {
    $mock = fakeNetwork([new HttpResponse(200, [], 'x')]);

    expect(uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => $url]))['url'][0])->toContain($message)
        ->and($mock->count())->toBe(1);
})->with([
    'file scheme' => ['file:///etc/passwd', 'Only http and https'],
    'ftp' => ['ftp://example.com/x.png', 'Only http and https'],
    'credentials' => ['https://user:pass@example.com/x.png', 'Only http and https'],
    'unresolvable' => ['https://nowhere.test/x.png', 'could not be resolved'],
]);

it('refuses hosts that curl would read as another address than the one it is pinned to', function () {
    // Regression: curl turns numeric hosts such as 2130706433 into 127.0.0.1 and ignores
    // the CURLOPT_RESOLVE pin for them. Real curl here, no MockHandler.
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if ($server === false) {
        test()->markTestSkipped("No local socket: {$error}");
    }

    stream_set_blocking($server, false);
    $port = (int) Str::afterLast((string) stream_socket_get_name($server, false), ':');
    // Pretends every name is public, so only the host check stands between curl and the listener.
    $remote = new RemoteFile(fn (string $host): array => ['93.184.216.34']);

    foreach (['2130706433', '0x7f000001', '0177.0.0.1', '127.1', '0x7f.0.0.1', '017700000001', 'localhost.', 'localhost..', 'example.com.', '127.0.0.1.nip.io.', 'ex%61mple.com', 'bücher.example'] as $host) {
        try {
            $remote->download("http://{$host}:{$port}/x", 1000);
            $message = 'downloaded';
        } catch (Illuminate\Validation\ValidationException $e) {
            $message = $e->errors()['url'][0];
        }

        expect($message)->toContain('is not a domain name or a standard IP address', $host)
            ->and(@stream_socket_accept($server, 0))->toBeFalse("{$host} reached the local listener");
    }

    // The listener does see a plain connection, so the assertions above can fail.
    $curl = curl_init("http://127.0.0.1:{$port}/");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 300]);
    curl_exec($curl);
    expect(@stream_socket_accept($server, 1))->not->toBeFalse();
});

it('checks every redirect hop again and stops at a private one', function () {
    $mock = fakeNetwork([
        new HttpResponse(302, ['Location' => 'https://cdn.example.com/a.png']),
        new HttpResponse(301, ['Location' => 'http://internal.test/admin.png']),
        new HttpResponse(200, [], 'secret'),
    ], ['internal.test' => ['192.168.0.10']]);

    $errors = uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png']));

    expect($errors['url'][0])->toContain('[internal.test] is not a public address')
        ->and($mock->count())->toBe(1)
        ->and(Storage::disk('assets')->allFiles())->toBe([]);
});

it('follows a few public redirects, pinning each hop', function () {
    $mock = fakeNetwork([
        new HttpResponse(302, ['Location' => 'https://cdn.example.com:8443/files/a.png']),
        new HttpResponse(200, [], $this->png),
    ]);

    tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png'])->assertHasNoErrors();

    expect($mock->getLastOptions()['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example.com:8443:[2606:2800:220:1::1]'])
        ->and(Storage::disk('assets')->exists('a.png'))->toBeTrue();
});

it('gives up after too many redirects', function () {
    fakeNetwork(array_fill(0, 5, new HttpResponse(302, ['Location' => 'https://example.com/again'])));

    expect(uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/start']))['url'][0])->toContain('redirects too often');
});

it('stops downloading a body larger than uploads.max_size', function (array $headers) {
    config(['statamic.content-mcp.uploads.max_size' => 1000]);
    fakeNetwork([new HttpResponse(200, $headers, str_repeat('a', 5000))]);

    expect(uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/big.txt']))['url'][0])->toContain('larger than the allowed')
        ->and(Storage::disk('assets')->allFiles())->toBe([]);
})->with([
    'announced by Content-Length' => [['Content-Length' => '5000']],
    'without Content-Length' => [[]],
]);

it('applies the allowed file types and the container validation rules', function () {
    fakeNetwork([new HttpResponse(200, [], '<?php echo 1;'), new HttpResponse(200, [], $this->png)]);
    AssetContainer::find('assets')->validationRules(['mimes:jpg'])->save();

    $php = uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/shell.php']));
    $png = uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/photo.png']));

    expect($php)->toHaveKey('filename')
        ->and($png['filename'][0])->toContain('jpg')
        ->and(Storage::disk('assets')->allFiles())->toBe([]);
});

it('never replaces an existing asset', function () {
    Storage::disk('assets')->put('photo.png', 'original');
    fakeNetwork([new HttpResponse(200, [], $this->png)]);

    $errors = uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/photo.png']));

    expect($errors['filename'][0])->toContain('[assets::photo.png]')
        ->and(Storage::disk('assets')->get('photo.png'))->toBe('original');
});

it('refuses uploads without the upload permission, before downloading', function () {
    $mock = fakeNetwork([new HttpResponse(200, [], $this->png)]);

    tool(editor(['access content mcp', 'view assets assets'], 'viewer@example.com'), UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png'])
        ->assertHasErrors(['You may not upload to [assets].']);

    expect($mock->count())->toBe(1);
});

it('refuses new folders without the folder permission and paths that leave the container', function () {
    $mock = fakeNetwork([new HttpResponse(200, [], $this->png)]);

    tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png', 'folder' => 'new'])
        ->assertHasErrors(['The folder [new] does not exist']);
    expect(uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png', 'folder' => '../outside'])))->toHaveKey('folder')
        ->and($mock->count())->toBe(1);
});

it('validates the data before it downloads anything', function () {
    $mock = fakeNetwork([new HttpResponse(200, [], $this->png)]);

    expect(uploadErrors(tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png', 'data' => ['credit' => str_repeat('x', 30)]])))->toHaveKey('credit')
        ->and($mock->count())->toBe(1);
});

it('removes the file again when a site listener refuses the asset', function () {
    Event::listen(AssetSaving::class, fn () => false);
    fakeNetwork([new HttpResponse(200, [], $this->png)]);

    tool($this->editor, UploadAsset::class, ['container' => 'assets', 'url' => 'https://example.com/a.png'])->assertHasErrors(['The site cancelled this save']);

    expect(Storage::disk('assets')->allFiles())->toBe([]);
});

it('describes the asset blueprint and tells the model it cannot upload chat attachments', function () {
    $fields = collect(tool($this->editor, GetBlueprint::class, ['asset_container' => 'assets'])->data()['fields'])->pluck('handle')->all();

    expect($fields)->toBe(['alt', 'credit'])
        ->and((new UploadAsset)->description())->toContain('cannot upload files attached to the chat');
});
