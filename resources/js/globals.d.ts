// What the Statamic CP provides at runtime to every addon script.
export {};

declare global {
    function __(
        key: string,
        replacements?: Record<string, string | number>,
    ): string;

    const Statamic: {
        booting(callback: () => void): void;
        $inertia: { register(name: string, component: unknown): void };
        $toast: {
            success(message: string): void;
            error(message: string): void;
        };
    };
}

declare module 'vue' {
    interface ComponentCustomProperties {
        __: typeof __;
    }
}
