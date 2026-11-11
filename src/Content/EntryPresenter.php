<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Statamic\CP\LivePreview;
use Statamic\Entries\Entry;
use Statamic\Facades\Site;
use Statamic\Facades\URL;

final class EntryPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(Entry $entry): array
    {
        return [
            'id' => $entry->id(),
            'collection' => $entry->collectionHandle(),
            ...(Site::multiEnabled() ? ['site' => $entry->locale()] : []),
            'title' => $entry->value('title'),
            'slug' => $entry->slug(),
            'status' => $entry->status(),
            'date' => $entry->hasDate() ? $entry->date()->toIso8601String() : null,
            'url' => $entry->absoluteUrl(),
            'updated_at' => $entry->lastModified()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(Entry $entry, bool $withPreview): array
    {
        return [
            'id' => $entry->id(),
            'collection' => $entry->collectionHandle(),
            ...(Site::multiEnabled() ? [
                'site' => $entry->locale(),
                'origin' => self::visibleId($entry->origin()),
                'localizations' => self::localizations($entry),
            ] : []),
            'blueprint' => $entry->blueprint()->handle(),
            'slug' => $entry->slug(),
            ...($entry->collection()->hasStructure() ? ['parent' => $entry->parent()?->id()] : []),
            'date' => $entry->hasDate() ? $entry->date()->toIso8601String() : null,
            'published' => $entry->published(),
            'status' => $entry->status(),
            'has_unpublished_changes' => $entry->revisionsEnabled() && $entry->hasWorkingCopy(),
            'url' => $entry->absoluteUrl(),
            'edit_url' => $entry->editUrl(),
            'preview_url' => $withPreview ? self::previewUrl($entry) : null,
        ];
    }

    /**
     * An entry's id, or null when the user may not view it.
     */
    private static function visibleId(mixed $entry): ?string
    {
        return $entry instanceof Entry && Gate::allows('view', $entry) ? $entry->id() : null;
    }

    /**
     * The ids of the entry in every site where it exists and the user may view it, by site handle.
     *
     * @return array<string, string>
     */
    private static function localizations(Entry $entry): array
    {
        $root = $entry->root();
        $all = $root->descendants()->put($root->locale(), $root);
        $localizations = [];

        foreach (Site::all()->keys() as $site) {
            $localization = $all->get($site);

            if ($localization instanceof Entry && Gate::allows('view', $localization)) {
                $localizations[$site] = $localization->id();
            }
        }

        return $localizations;
    }

    /**
     * A Live Preview link, built like the CP's PreviewController. Anyone holding it
     * can view the draft until the token expires.
     */
    private static function previewUrl(Entry $entry): ?string
    {
        $target = $entry->previewTargets()->first();

        if (! is_array($target) || ! is_string($target['url'] ?? null) || $target['url'] === '') {
            return null;
        }

        // Cloned: the supplement would otherwise stick to the Stache's cached instance.
        $preview = (clone $entry)->setSupplement('live_preview', true);
        $token = app(LivePreview::class)->tokenize(null, $preview)->token();
        $url = URL::makeAbsolute($target['url']);

        return $url.(str_contains($url, '?') ? '&' : '?').'live-preview='.Str::random().'&token='.$token;
    }
}
