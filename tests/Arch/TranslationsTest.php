<?php

declare(strict_types=1);

/*
 * Every string the addon shows passes through __(). A key missing from lang/*.json
 * shows up in English in a German Control Panel; Statamic's own keys may be reused.
 */
it('translates every string of the addon into English and German', function () {
    $root = dirname(__DIR__, 2);
    $files = [
        ...glob("{$root}/resources/js/{,*/}*.{vue,ts}", GLOB_BRACE),
        ...glob("{$root}/resources/views/{,*/}*.blade.php", GLOB_BRACE),
        ...new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$root}/src")), '/\.php$/'),
    ];

    $keys = [];

    foreach ($files as $file) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/s", (string) file_get_contents((string) $file), $matches);
        $keys = [...$keys, ...array_map(fn (string $key): string => stripslashes($key), $matches[1])];
    }

    // Permission labels are translated by Statamic when it renders them.
    preg_match_all("/->label\\('((?:[^'\\\\]|\\\\.)*)'\\)/", (string) file_get_contents("{$root}/src/ContentMcpServiceProvider.php"), $labels);
    $keys = array_unique([...$keys, ...array_map(stripslashes(...), $labels[1])]);
    // Namespaced (statamic::…) and PHP file keys (validation.integer) come from Statamic and Laravel.
    $keys = array_filter($keys, fn (string $key): bool => ! str_contains($key, '::') && preg_match('/^[a-z_]+\.[a-z_.]+$/', $key) !== 1);

    $statamic = json_decode((string) file_get_contents("{$root}/vendor/statamic/cms/lang/de.json"), true);

    foreach (['en', 'de'] as $locale) {
        $ours = json_decode((string) file_get_contents("{$root}/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
        $missing = array_values(array_filter($keys, fn (string $key): bool => ! isset($ours[$key]) && ! isset($statamic[$key])));

        expect($missing)->toBe([], "missing in lang/{$locale}.json");
    }

    expect(count($keys))->toBeGreaterThan(30);
});
