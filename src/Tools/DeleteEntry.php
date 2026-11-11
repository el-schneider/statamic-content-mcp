<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Entries\Entry;

#[Title('Delete entry')]
#[Description('Deletes an entry for good; there is no trash. Only call this when the editor explicitly asks to delete. Child pages in a page tree move up to the deleted page\'s parent. An entry with versions in other sites needs `localizations`: "delete" removes them too, "detach" keeps them as independent entries.')]
#[IsDestructive]
#[IsIdempotent]
final class DeleteEntry extends ContentTool
{
    protected string $name = 'delete_entry';

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.deletes');
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'localizations' => $schema->string()->enum(['delete', 'detach'])->description('What happens to versions of the entry in other sites.'),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string'], 'localizations' => ['nullable', 'in:delete,detach']]);

        $entry = Find::entry($request->string('id')->toString());

        if (Gate::denies('delete', $entry)) {
            return Response::error('You may not delete this entry.');
        }

        // The CP offers no delete for the root page of a page tree.
        if ($entry->page()?->isRoot()) {
            return Response::error('The root page of a page tree cannot be deleted.');
        }

        if ($entry->descendants()->isNotEmpty()) {
            $this->handleLocalizations($entry, $request->string('localizations')->toString());
        }

        NotSaved::unless($entry->delete(), deletion: true);

        return Response::structured(['deleted' => $entry->id()]);
    }

    /**
     * Mirrors the CP's DeleteMultisiteEntry action: deleting them is only offered when
     * the editor may access every site they are in; otherwise they are detached.
     */
    private function handleLocalizations(Entry $entry, string $choice): void
    {
        $canDelete = $entry->descendants()->every(fn (Entry $localization): bool => Gate::allows('view', $localization->site()));

        if ($choice === '' || ($choice === 'delete' && ! $canDelete)) {
            throw ValidationException::withMessages(['localizations' => [$canDelete
                ? 'This entry has versions in other sites. Pass "delete" to delete them too or "detach" to keep them as independent entries.'
                : 'This entry has versions in sites you may not access. Pass "detach" to keep them as independent entries.']]);
        }

        $choice === 'delete' ? $entry->deleteDescendants() : $entry->detachLocalizations();
    }
}
