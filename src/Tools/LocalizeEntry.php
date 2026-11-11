<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Entries\Entry;
use Statamic\Facades\Site;

#[Title('Localize entry')]
#[Description('Creates the version of an entry for another site, like "Localize" in the Control Panel. The new localization starts empty and shows the values of the entry it was made from until you set its own with update_entry (only localizable fields). It gets the same published state. Returns the new entry\'s meta; its id is in `localizations` of every version.')]
#[IsDestructive(false)]
#[IsIdempotent(false)]
final class LocalizeEntry extends ContentTool
{
    protected string $name = 'localize_entry';

    public function shouldRegister(): bool
    {
        return Site::multiEnabled();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Id of the entry to localize; the new localization takes it as its origin.')->required(),
            'site' => $schema->string()->description('Handle of the site to create the localization in, from get_overview.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string'], 'site' => ['required', 'string']]);

        $entry = Find::entry($request->string('id')->toString());
        $site = $this->site($request);

        // The CP's LocalizeEntryController authorizes `edit` on the entry and offers only accessible sites.
        if (Gate::denies('edit', $entry)) {
            return Response::error('You may not edit this entry.');
        }

        if (! $entry->collection()->sites()->contains($site->handle())) {
            return Response::error("The collection [{$entry->collectionHandle()}] does not exist in [{$site->handle()}].");
        }

        $existing = $entry->in($site->handle());

        if ($existing instanceof Entry) {
            return Response::error("The entry already exists in [{$site->handle()}]: [{$existing->id()}].");
        }

        $localization = $entry->makeLocalization($site->handle());
        $saved = $localization->updateLastModified($this->user())->save();

        // What Entry::store() does, except that its revision result would hide a cancelled entry save.
        if ($saved && $localization->revisionsEnabled()) {
            $saved = $localization->makeRevision()->user($this->user())->save();
        }

        NotSaved::unless($saved);

        return Response::structured(EntryPresenter::meta($localization, withPreview: true));
    }
}
