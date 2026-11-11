<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\ContentTool;
use Laravel\Mcp\Server\Tool;

arch('every file declares strict types')
    ->expect('ElSchneider\ContentMcp')
    ->toUseStrictTypes();

arch('classes are final unless they exist to be extended')
    ->expect('ElSchneider\ContentMcp')
    ->classes()
    ->toBeFinal()
    ->ignoring(ContentTool::class);

arch('no debugging leftovers')
    ->expect(['dd', 'dump', 'ddd', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

/*
 * Claude asks for approval per tool and auto-approves read-only ones, so a new
 * tool must not ship without saying which kind it is.
 */
it('annotates every registered tool as read-only or lists it as a write tool', function () {
    $writeTools = ['create_entry', 'update_entry', 'publish_entry', 'unpublish_entry', 'update_global', 'localize_entry', 'create_term', 'update_term', 'update_asset', 'upload_asset'];

    /** @var list<class-string<Tool>> $classes */
    $classes = (new ReflectionClass(ContentMcpServer::class))->getProperty('tools')->getDefaultValue();

    foreach ($classes as $class) {
        $tool = new $class;
        $annotations = $tool->annotations();

        if (in_array($tool->name(), $writeTools, true)) {
            expect($annotations)->not->toHaveKey('readOnlyHint', "{$tool->name()} writes")
                ->and($annotations)->toHaveKeys(['destructiveHint', 'idempotentHint']);
        } else {
            expect($annotations['readOnlyHint'] ?? null)->toBeTrue("{$tool->name()} is neither read-only nor a listed write tool");
        }
    }

    expect(count($classes))->toBe(19);
});
