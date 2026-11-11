<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http;

use Illuminate\Http\Request;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\ClientRepository;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\User;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Resolves the Statamic user behind a Passport access token. Passport's own
 * guard needs an Eloquent user provider; this one works with file users too.
 */
final readonly class ResolveBearerTokenUser
{
    public function __construct(
        private ResourceServer $server,
        private ClientRepository $clients,
    ) {}

    public function __invoke(Request $request): ?UserContract
    {
        if (! $request->bearerToken()) {
            return null;
        }

        try {
            // Checks signature, expiry and revocation.
            $token = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return null;
        }

        $scopes = $token->getAttribute('oauth_scopes', []);
        $clientId = $token->getAttribute('oauth_client_id');
        $userId = $token->getAttribute('oauth_user_id');

        if (! is_array($scopes) || ! in_array(Registrar::OAUTH_SCOPE, $scopes, true)) {
            return null;
        }

        if (! is_string($clientId) || ! $this->clients->findActive($clientId)) {
            return null;
        }

        return is_string($userId) && $userId !== '' ? User::find($userId) : null;
    }
}
