<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Console\Commands;

use ElSchneider\ContentMcp\Support\StaticTokenUserResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Contracts\Auth\User as StatamicUser;

final class GenerateStaticTokenCommand extends Command
{
    protected $signature = 'content-mcp:generate-static-token
        {--user= : Statamic user email or id to pair with the token}
        {--length=64 : Token length in characters}
        {--raw : Output only the token}';

    protected $description = 'Generate a strong static bearer token for Content MCP';

    public function __construct(private readonly StaticTokenUserResolver $userResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $length = $this->validatedLength();

        if ($length === null) {
            return self::FAILURE;
        }

        $userIdentifier = $this->validatedUserIdentifier();

        if ($userIdentifier === null) {
            return self::FAILURE;
        }

        $token = Str::random($length);

        if ($this->option('raw')) {
            $this->line($token);

            return self::SUCCESS;
        }

        $this->components->info('Generated static token for Content MCP.');
        $this->newLine();
        $this->line('STATAMIC_CONTENT_MCP_AUTH_DRIVER=static_token');
        $this->line("STATAMIC_CONTENT_MCP_STATIC_TOKEN={$token}");
        $this->line("STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER={$userIdentifier}");
        $this->newLine();
        $this->components->bulletList([
            'Add these values to your .env file.',
            'Keep the token secret and rotate it if it is ever exposed.',
            'Use --user=<email-or-id> to validate the target Statamic user before rollout.',
        ]);

        return self::SUCCESS;
    }

    private function validatedLength(): ?int
    {
        $length = filter_var($this->option('length'), FILTER_VALIDATE_INT);

        if ($length === false || $length < 32 || $length > 128) {
            $this->components->error('The --length option must be an integer between 32 and 128.');

            return null;
        }

        return $length;
    }

    private function validatedUserIdentifier(): ?string
    {
        $identifier = $this->option('user');

        if (! is_string($identifier) || $identifier === '') {
            $this->components->error('The --user option is required and must be a Statamic user email or id.');

            return null;
        }

        $user = $this->userResolver->resolve($identifier);

        if ($user instanceof StatamicUser) {
            return $identifier;
        }

        $this->components->error("Could not find Statamic user [{$identifier}].");

        return null;
    }
}
