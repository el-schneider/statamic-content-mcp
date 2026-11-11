<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Controllers;

use ElSchneider\ContentMcp\Connections;
use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Every user's connections, for those who may manage them, and disconnecting.
 */
final class ConnectionsController extends CpController
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function present(?string $userId): array
    {
        return array_map(fn (array $connection): array => [
            ...$connection,
            'disconnect_url' => cp_route('content-mcp.connections.destroy', ['client' => $connection['client_id'], 'user' => $connection['user_id']]),
        ], Connections::all($userId));
    }

    public function index(): Response
    {
        $this->authorize(ContentMcpServiceProvider::MANAGE_PERMISSION);

        return Inertia::render('content-mcp::Connections', [
            'connections' => self::present(null),
            'tabs' => Area::tabs('connections'),
        ]);
    }

    /**
     * Anyone may disconnect their own apps; other users' need the manage permission.
     */
    public function destroy(string $client, string $user): RedirectResponse
    {
        $own = $user === Area::currentUserId();
        $this->authorize($own && Area::canConnect() ? ContentMcpServiceProvider::PERMISSION : ContentMcpServiceProvider::MANAGE_PERMISSION);

        if (Connections::disconnect($client, $user) === 0) {
            abort(404);
        }

        return back()->with('success', __('Disconnected. The app has to connect again before it can work for this account.'));
    }
}
