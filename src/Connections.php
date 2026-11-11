<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;

/**
 * Connections of AI apps, derived from Passport's tables: one per (user, OAuth client)
 * pair that has an access token that is not revoked or a refresh token still usable.
 * Passport records when tokens are issued, not when they are used.
 */
final class Connections
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(?string $userId = null): array
    {
        $now = CarbonImmutable::now()->toDateTimeString();
        $tokens = Passport::token();
        $refresh = Passport::refreshToken();
        $tokenTable = $tokens->getTable();
        $refreshTable = $refresh->getTable();

        // Bound values, not literals: Postgres has real booleans, SQLite and MySQL store 0/1.
        $rows = $tokens->getConnection()->table("{$tokenTable} as t")
            ->leftJoin("{$refreshTable} as r", 'r.access_token_id', '=', 't.id')
            // Only tokens of this addon; a site may use Passport for its own API too.
            ->where('t.scopes', 'like', '%"'.Registrar::OAUTH_SCOPE.'"%')
            ->when($userId !== null, fn (Builder $query) => $query->where('t.user_id', $userId))
            ->whereNotNull('t.user_id')
            ->groupBy('t.user_id', 't.client_id')
            ->select('t.user_id', 't.client_id')
            ->selectRaw('min(t.created_at) as first_issued_at, max(t.created_at) as last_issued_at')
            ->selectRaw('max(case when t.revoked = ? and t.expires_at > ? then 1 else 0 end) as access_live', [false, $now])
            ->selectRaw('max(case when t.revoked = ? then 1 else 0 end) as access_unrevoked', [false])
            ->selectRaw('max(case when r.revoked = ? and (r.expires_at is null or r.expires_at > ?) then 1 else 0 end) as refresh_live', [false, $now])
            ->havingRaw('max(case when t.revoked = ? then 1 else 0 end) = 1 or max(case when r.revoked = ? and (r.expires_at is null or r.expires_at > ?) then 1 else 0 end) = 1', [false, false, $now])
            ->orderByDesc('last_issued_at')
            ->get();

        /** @var Collection<string, Client> $clients */
        $clients = Passport::client()->newQuery()->whereIn('id', $rows->pluck('client_id')->unique()->all())->get()->keyBy(fn (Client $client): string => (string) $client->getKey());

        return array_values($rows->map(function (object $row) use ($clients): array {
            $client = $clients->get((string) $row->client_id);
            $user = User::find((string) $row->user_id);
            $redirect = ((array) $client?->getAttribute('redirect_uris'))[0] ?? null;

            return [
                'client_id' => (string) $row->client_id,
                'user_id' => (string) $row->user_id,
                'app' => (string) ($client?->getAttribute('name') ?? $row->client_id),
                'redirect_host' => is_string($redirect) ? (parse_url($redirect, PHP_URL_HOST) ?: null) : null,
                'user' => $user instanceof StatamicUser ? ['id' => (string) $user->getAuthIdentifier(), 'name' => $user->name(), 'email' => $user->email()] : null,
                'first_connected_at' => CarbonImmutable::parse((string) $row->first_issued_at)->toIso8601String(),
                'last_token_issued_at' => CarbonImmutable::parse((string) $row->last_issued_at)->toIso8601String(),
                'state' => match (true) {
                    (bool) $row->access_live => 'active',
                    (bool) $row->refresh_live => 'idle',
                    default => 'expired',
                },
            ];
        })->all());
    }

    /**
     * Revokes the pair's access tokens, refresh tokens and pending authorization codes.
     * The app's next request gets a 401 and it has to connect again.
     *
     * @return int the number of access tokens the pair had
     */
    public static function disconnect(string $clientId, string $userId): int
    {
        $tokens = Passport::token()->newQuery()->where('client_id', $clientId)->where('user_id', $userId);
        $count = (clone $tokens)->count();

        Passport::refreshToken()->newQuery()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);
        Passport::authCode()->newQuery()->where('client_id', $clientId)->where('user_id', $userId)->update(['revoked' => true]);

        return $count;
    }
}
