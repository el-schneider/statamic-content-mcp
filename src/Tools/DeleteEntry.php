<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use ElSchneider\ContentMcp\Settings;
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
        return Settings::deletes();
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
     * the editor may access every site they are in; otherwise they are detached. Unlike
     * Entry::deleteDescendants(), each one must also pass `delete`, and a veto stops.
     */
    private function handleLocalizations(Entry $entry, string $choice): void
    {
        $localizations = $entry->descendants();
        $inSites = $localizations->every(fn (Entry $localization): bool => Gate::allows('view', $localization->site()));

        if ($choice === '' || ($choice === 'delete' && ! $inSites)) {
            throw ValidationException::withMessages(['localizations' => [$inSites
                ? 'This entry has versions in other sites. Pass "delete" to delete them too or "detach" to keep them as independent entries.'
                : 'This entry has versions in sites you may not access. Pass "detach" to keep them as independent entries.']]);
        }

        if ($choice === 'detach') {
            $entry->detachLocalizations();

            return;
        }

        if ($localizations->contains(fn (Entry $localization): bool => Gate::denies('delete', $localization))) {
            throw ValidationException::withMessages(['localizations' => ['You may not delete every version of this entry in other sites. Pass "detach" to keep them as independent entries.']]);
        }

        $this->deleteLocalizations($entry);
    }

    /**
     * Deepest first: an entry with localizations cannot be deleted.
     */
    private function deleteLocalizations(Entry $entry): void
    {
        foreach ($entry->directDescendants() as $localization) {
            $this->deleteLocalizations($localization);
            NotSaved::unless($localization->delete(), deletion: true);
        }
    }
}
