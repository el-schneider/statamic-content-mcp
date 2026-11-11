<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Statamic\Facades\Site;
use Statamic\Taxonomies\LocalizedTerm;

final class TermPresenter
{
    /**
     * The term's meta and its values in agent form; in a non-default site, what it
     * resolves to plus the fields it sets itself.
     *
     * @return array<string, mixed>
     */
    public static function present(LocalizedTerm $term): array
    {
        $fields = $term->blueprint()->fields();

        return [
            'taxonomy' => $term->taxonomyHandle(),
            'slug' => $term->slug(),
            ...(Site::multiEnabled() ? [
                'site' => $term->locale(),
                'localized_fields' => $term->isRoot() ? null : array_values(array_intersect($term->data()->keys()->all(), $fields->all()->keys()->all())),
            ] : []),
            'title' => $term->title(),
            'url' => $term->absoluteUrl(),
            'edit_url' => $term->editUrl(),
            'data' => AgentForm::ofBlueprint($term->blueprint(), ContentWriter::values($term)),
        ];
    }
}
