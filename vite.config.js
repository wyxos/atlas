import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from "@laravel/vite-plugin-wayfinder";

export default defineConfig({
    plugins: [
        wayfinder(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': '/resources/js',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (!id.includes('node_modules')) {
                        return;
                    }

                    const normalizedId = id.replace(/\\/g, '/');

                    if (normalizedId.includes('/node_modules/vue') || normalizedId.includes('/node_modules/@vue')) {
                        return 'vendor-vue';
                    }

                    if (normalizedId.includes('/node_modules/@oruga-ui') || normalizedId.includes('/node_modules/reka-ui')) {
                        return 'vendor-ui';
                    }

                    if (normalizedId.includes('/node_modules/@unovis')) {
                        return 'vendor-charts';
                    }

                    if (normalizedId.includes('/node_modules/@sentry')) {
                        return 'vendor-sentry';
                    }

                    if (normalizedId.includes('/node_modules/laravel-echo') || normalizedId.includes('/node_modules/pusher-js')) {
                        return 'vendor-realtime';
                    }

                    if (normalizedId.includes('/node_modules/lucide') || normalizedId.includes('/node_modules/lucide-vue-next')) {
                        return 'vendor-icons';
                    }

                    if (normalizedId.includes('/node_modules/embla-carousel') || normalizedId.includes('/node_modules/@internationalized/date')) {
                        return 'vendor-media';
                    }

                    return 'vendor';
                },
            },
        },
    },
});
