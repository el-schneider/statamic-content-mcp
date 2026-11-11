<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;

/**
 * The boundary where expected failures become tool errors the model can act on.
 */
abstract class ContentTool extends Tool
{
    abstract protected function respond(Request $request): Response|ResponseFactory;

    final public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->respond($request);
        } catch (NotFound $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            // laravel/mcp would flatten the messages and drop the field names.
            return Response::error((string) json_encode([
                'message' => 'Validation failed. Fix these fields and try again.',
                'errors' => $e->errors(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    protected function user(): StatamicUser
    {
        $user = User::current();

        return $user instanceof StatamicUser ? $user : throw new AuthenticationException;
    }
}
