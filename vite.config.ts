import statamic from '@statamic/cms/vite-plugin';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite-plus';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/cp.ts'],
            publicDirectory: 'resources/dist',
        }),
        statamic(),
    ],
    lint: {
        ignorePatterns: ['vendor/**', 'node_modules/**', 'resources/dist/**'],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'vendor/**',
            'resources/dist/**',
            'tests/**',
        ],
    },
});
