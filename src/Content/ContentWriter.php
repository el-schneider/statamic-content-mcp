<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Collection as Values;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Statamic\Auth\User;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Routing\UrlBuilder;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Stache;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Fields;
use Statamic\Globals\Variables;
use Statamic\Sites\Site;
use Statamic\Structures\CollectionTree;
use Statamic\Support\Arr;

/**
 * Writes agent-form data through the Control Panel's pipeline
 * (preProcess → validate → process), mirroring the CP's EntriesController
 * and GlobalVariablesController minus UI concerns. Saves fire the normal events.
 */
final class ContentWriter
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function createEntry(User $user, Collection $collection, Blueprint $blueprint, Site $site, array $data, ?string $parent): Entry
    {
        $fields = $blueprint->fields();
        self::assertWritable($fields, array_keys($data), self::lockedHandles($collection, $blueprint));

        // The CP makes such editors the author of what they create.
        if (self::authorLocked($collection, $blueprint)) {
            $data['author'] = [$user->getAuthIdentifier()];
        }

        $fields = $fields->addValues(self::withNowDefaults($fields, AgentForm::toStored($fields, $data, [])))->preProcess();

        $fields->validator()
            ->withRules(Entries::createRules($collection, $site))
            ->withReplacements(['collection' => $collection->handle(), 'site' => $site->handle()])
            ->validate();

        $values = $fields->process()->values()->except(['slug', 'blueprint', 'published']);

        /** @var Entry $entry */
        $entry = Entries::make();
        $entry->collection($collection)->blueprint($blueprint->handle())->locale($site->handle())->published(false);
        $entry->slug(self::slug($entry, $data));

        if ($collection->dated()) {
            $entry->date(self::date($blueprint, $values));
        }

        $entry->data($values);

        $tree = $collection->structure()?->in($site->handle());

        if ($tree instanceof CollectionTree && $parent !== null && ! $tree->find($parent)) {
            throw ValidationException::withMessages(['parent' => "No page with id [{$parent}] in this collection's tree."]);
        }

        // Without a parent the CP appends to the end of the top level. Orderable
        // (one level deep) collections are appended by Entry::save() itself.
        if ($tree instanceof CollectionTree && ! $collection->orderable()) {
            $appendTo = $parent !== null && $tree->find($parent)?->isRoot() ? null : $parent;
            $entry->afterSave(fn (Entry $entry) => $tree->appendTo($appendTo, $entry)->save());
        }

        self::assertUniqueUri($entry, $tree instanceof CollectionTree ? $tree : null, $parent);

        if ($entry->revisionsEnabled()) {
            $entry->store(['user' => $user]);
        } else {
            $entry->updateLastModified($user)->save();
        }

        return $entry;
    }

    /**
     * Never changes the published state. With revisions on, a published entry
     * gets a working copy like in the CP.
     *
     * @param  array<array-key, mixed>  $changes
     */
    public static function updateEntry(User $user, Entry $entry, array $changes): Entry
    {
        $entry = $entry->fromWorkingCopy();
        $collection = $entry->collection();
        $blueprint = $entry->blueprint();
        $fields = $blueprint->fields();
        $handles = DataPatch::handles($changes);

        self::assertWritable($fields, $handles, self::lockedHandles($collection, $blueprint));

        // The values the CP edit form starts from.
        $current = [...Arr::removeNullValues($entry->data()->all()), 'slug' => $entry->slug()];

        if ($collection->dated()) {
            $current['date'] = mb_substr($entry->date()->setTimezone(config('app.timezone'))->toDateTimeString(), 0, 19);
        }

        $preProcessed = $fields->addValues(self::withNowDefaults($fields, self::merge($fields, $current, $changes, $handles)))->preProcess();

        $preProcessed->validator()
            ->withRules(Entries::updateRules($collection, $entry))
            ->withReplacements(['id' => $entry->id(), 'collection' => $collection->handle(), 'site' => $entry->locale()])
            ->validate();

        $values = self::written($fields, $preProcessed, $current, $handles);

        if ($values->has('slug')) {
            $entry->slug($values->pull('slug'));
        }

        if ($values->has('date')) {
            $entry->date(self::date($blueprint, $values));
        }

        $entry->merge($values->all());

        $tree = $collection->structure()?->in($entry->locale());
        self::assertUniqueUri($entry, $tree instanceof CollectionTree ? $tree : null, $entry->parent()?->id());

        if ($entry->revisionsEnabled() && $entry->published()) {
            $entry->makeWorkingCopy()->user($user)->save();

            return $entry->fromWorkingCopy();
        }

        $entry->updateLastModified($user)->save();

        return $entry;
    }

    /**
     * @param  array<array-key, mixed>  $changes
     */
    public static function updateGlobal(Variables $variables, array $changes): Variables
    {
        $fields = $variables->blueprint()->fields();
        $handles = DataPatch::handles($changes);
        self::assertWritable($fields, $handles, []);

        $current = Arr::removeNullValues($variables->data()->all());
        $preProcessed = $fields->addValues(self::withNowDefaults($fields, self::merge($fields, $current, $changes, $handles)))->preProcess();
        $preProcessed->validate();

        $variables->merge(self::written($fields, $preProcessed, $current, $handles)->all())->save();

        return $variables;
    }

    /**
     * Applies the changes in agent form and converts the touched fields back.
     *
     * @param  array<array-key, mixed>  $current
     * @param  array<array-key, mixed>  $changes
     * @param  list<string>  $handles
     * @return array<array-key, mixed>
     */
    private static function merge(Fields $fields, array $current, array $changes, array $handles): array
    {
        $patched = DataPatch::apply(AgentForm::fromStored($fields, Arr::only($current, $handles)), $changes);

        return [...$current, ...AgentForm::toStored($fields, $patched, $current)];
    }

    /**
     * The processed values of the touched fields. Only these are written, and
     * within them every part the change left alone is swapped back for its stored
     * original, because processing normalises it (e.g. adds `enabled: true` to sets).
     *
     * @param  array<array-key, mixed>  $current
     * @param  list<string>  $handles
     * @return Values<array-key, mixed>
     */
    private static function written(Fields $fields, Fields $preProcessed, array $current, array $handles): Values
    {
        $before = $fields->addValues(self::withNowDefaults($fields, $current))->preProcess()->process()->values();

        return $preProcessed->process()->values()->only($handles)->map(
            fn (mixed $value, int|string $handle): mixed => array_key_exists($handle, $current)
                ? AgentForm::keepUnchanged($value, $before->get($handle), $current[$handle])
                : $value,
        );
    }

    /**
     * The CP's date picker turns a `now` default into the current time before it
     * submits; Statamic's date validation rejects the literal "now".
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function withNowDefaults(Fields $fields, array $values): array
    {
        foreach ($fields->all() as $handle => $field) {
            if ($field->type() === 'date' && ! isset($values[$handle]) && $field->defaultValue() === 'now') {
                $values[$handle] = now()->toDateTimeString();
            }
        }

        return $values;
    }

    /**
     * @param  list<int|string>  $handles
     * @param  list<string>  $locked
     */
    private static function assertWritable(Fields $fields, array $handles, array $locked): void
    {
        $errors = [];

        foreach ($handles as $handle) {
            $field = $fields->get($handle);

            if ($field === null) {
                $errors[$handle] = ["Unknown field [{$handle}]. Call get_blueprint for this blueprint's fields."];
            } elseif (in_array($field->visibility(), ['read_only', 'computed'], true) || in_array($handle, $locked, true)) {
                $errors[$handle] = ['This field is read-only.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fields the CP never takes from this editor's submitted values.
     *
     * @return list<string>
     */
    private static function lockedHandles(Collection $collection, Blueprint $blueprint): array
    {
        return [
            ...($collection->autoGeneratesTitles() ? ['title'] : []),
            ...(self::authorLocked($collection, $blueprint) ? ['author'] : []),
        ];
    }

    private static function authorLocked(Collection $collection, Blueprint $blueprint): bool
    {
        return Gate::denies('edit-other-authors-entries', [EntryContract::class, $collection, $blueprint]);
    }

    /**
     * @param  Values<array-key, mixed>  $values
     */
    private static function date(Blueprint $blueprint, Values $values): mixed
    {
        return $blueprint->field('date')?->fieldtype()->augment($values->pull('date'));
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function slug(Entry $entry, array $data): ?string
    {
        if (is_string($data['slug'] ?? null) && $data['slug'] !== '') {
            return $data['slug'];
        }

        if (! $entry->blueprint()->hasField('slug')) {
            return null;
        }

        $title = $entry->collection()->autoGeneratesTitles() ? null : ($data['title'] ?? null);

        return Str::slug(is_string($title) ? $title : $entry->autoGeneratedTitle(), '-', $entry->site()->lang());
    }

    /**
     * Mirrors EntriesController::validateUniqueUri(): two entries may not share a URL.
     * A new structured entry isn't in the tree yet, so its URI is built under the parent.
     */
    private static function assertUniqueUri(Entry $entry, ?CollectionTree $tree, ?string $parent): void
    {
        if (! $route = $entry->route()) {
            return;
        }

        if ($tree === null) {
            $uri = app(UrlBuilder::class)->content($entry)->merge(['id' => $entry->id() ?? Stache::generateId()])->build($route);
        } else {
            $parentPage = $parent !== null ? $tree->find($parent) : null;
            $parentPage = $parentPage?->isRoot() ? null : $parentPage;

            $uri = app(UrlBuilder::class)->content($entry)->merge([
                'parent_uri' => $parentPage?->uri(),
                'slug' => $entry->slug(),
                'depth' => $parentPage ? $parentPage->depth() + 1 : 1,
                'is_root' => false,
            ])->build($route);
        }

        $existing = Entries::findByUri($uri, $entry->locale());

        if ($existing instanceof Entry && $existing->id() !== $entry->id()) {
            throw ValidationException::withMessages(['slug' => __('statamic::validation.unique_uri')]);
        }
    }
}
