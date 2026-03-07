<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Support\BardToMarkdown;
use ElSchneider\ContentMcp\Support\FieldValuePreparer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Statamic\Facades\GlobalSet;

final class GlobalsTool extends Tool
{
    protected string $name = 'globals';

    protected string $description = 'List, get, and update global sets. Globals store site-wide settings and reusable content.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list', 'get', 'update'])
                ->description('Action to perform.'),
            'handle' => $schema->string()
                ->description('Global set handle (for get, update).'),
            'data' => $schema->object()
                ->description('Field data for update. Use blueprints.describe to discover available fields.'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.globals', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['required', 'string', 'in:list,get,update'],
        ]);

        return match ($request->get('action')) {
            'list' => $this->list(),
            'get' => $this->get($request),
            'update' => $this->update($request),
            default => Response::error('Unknown action.'),
        };
    }

    private function list(): Response
    {
        $sets = GlobalSet::all()->map(fn ($set) => [
            'handle' => $set->handle(),
            'title' => $set->title(),
        ])->values()->all();

        return Response::json(['data' => $sets]);
    }

    private function get(Request $request): Response
    {
        $request->validate([
            'handle' => ['required', 'string'],
        ]);

        $handle = $request->get('handle');
        $globalSet = GlobalSet::findByHandle($handle);

        if (! $globalSet) {
            return Response::error("Global set [{$handle}] not found.");
        }

        $variables = $globalSet->inDefaultSite();
        $blueprint = $globalSet->blueprint();

        $fieldValues = [];
        foreach ($blueprint->fields()->all() as $field) {
            if ($field->type() === 'section') {
                continue;
            }

            $value = $variables->get($field->handle());
            if ($value === null) {
                continue;
            }

            $fieldValues[$field->handle()] = match ($field->type()) {
                'bard' => BardToMarkdown::convert($value),
                default => $value,
            };
        }

        return Response::json([
            'handle' => $globalSet->handle(),
            'title' => $globalSet->title(),
            'blueprint' => $blueprint->handle(),
            'edit_url' => '/cp/globals/'.$globalSet->handle(),
            'data' => $fieldValues,
        ]);
    }

    private function update(Request $request): Response
    {
        $request->validate([
            'handle' => ['required', 'string'],
            'data' => ['required', 'array'],
        ]);

        $handle = $request->get('handle');
        $globalSet = GlobalSet::findByHandle($handle);

        if (! $globalSet) {
            return Response::error("Global set [{$handle}] not found.");
        }

        $variables = $globalSet->inDefaultSite();
        $blueprint = $globalSet->blueprint();

        $incoming = FieldValuePreparer::prepare($request->get('data'), $blueprint);
        $merged = array_merge($variables->data()->all(), $incoming);

        try {
            $blueprint->fields()
                ->addValues($merged)
                ->validator()
                ->validator()
                ->validate();
        } catch (ValidationException $e) {
            $details = collect($e->errors())
                ->map(fn ($messages) => implode(', ', $messages))
                ->implode('; ');

            return Response::error("Validation failed: {$details}");
        }

        $variables->data($merged);
        $variables->save();

        return Response::json([
            'handle' => $globalSet->handle(),
            'title' => $globalSet->title(),
            'blueprint' => $blueprint->handle(),
            'edit_url' => '/cp/globals/'.$globalSet->handle(),
            'data' => $merged,
        ]);
    }
}
