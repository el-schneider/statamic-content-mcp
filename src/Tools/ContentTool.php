<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotFound;
use ElSchneider\ContentMcp\Content\NotSaved;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Statamic\Sites\Site;

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
        } catch (NotFound|NotSaved $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            // laravel/mcp would flatten the messages and drop the field names.
            return Response::error((string) json_encode([
                'message' => 'Validation failed. Fix these fields and try again.',
                'errors' => $e->errors(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    protected static function siteArgument(JsonSchema $schema): Type
    {
        return $schema->string()->description('Site handle, from get_overview. Defaults to the default site.');
    }

    /**
     * The site of the `site` argument; a site the user may not access is reported as unknown.
     */
    protected function site(Request $request): Site
    {
        return Find::site($request->filled('site') ? $request->string('site')->toString() : null);
    }

    protected function user(): StatamicUser
    {
        $user = User::current();

        return $user instanceof StatamicUser ? $user : throw new AuthenticationException;
    }
}
