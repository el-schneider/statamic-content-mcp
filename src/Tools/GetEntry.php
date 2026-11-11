<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\AgentForm;
use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Entries\Entry;
use Statamic\Facades\Site;

#[Title('Get entry')]
#[Description('Returns one entry: its meta (status, url, edit_url in the Control Panel, preview_url for unpublished content) and `data`, the field values in the same shape update_entry accepts. Rich text (Bard) fields are HTML, or a list of blocks when the field has sets.')]
#[IsReadOnly]
final class GetEntry extends ContentTool
{
    protected string $name = 'get_entry';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        // With revisions, the unpublished working copy is what the editor edits in the CP.
        $entry = Find::entry($request->string('id')->toString())->fromWorkingCopy();
        $unpublished = $entry->status() !== 'published' || ($entry->revisionsEnabled() && $entry->hasWorkingCopy());

        return Response::structured([
            ...EntryPresenter::meta($entry, withPreview: $unpublished),
            // A localization shows what it resolves to; these are the fields it sets itself.
            ...(Site::multiEnabled() ? ['localized_fields' => $entry->hasOrigin() ? self::localizedFields($entry) : null] : []),
            'data' => AgentForm::ofBlueprint($entry->blueprint(), ContentWriter::values($entry)),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function localizedFields(Entry $entry): array
    {
        return array_values(array_intersect($entry->data()->keys()->all(), $entry->blueprint()->fields()->all()->keys()->all()));
    }
}
