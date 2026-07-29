import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            // app.js يبقى لصفحات Blade المتبقّية (لوحة المنصة)
            // app.tsx هو نقطة دخول Inertia للوحة المتجر ونقاط البيع
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Tajawal', {
                    weights: [300, 400, 500, 700, 800],
                }),
                bunny('Cairo', {
                    weights: [400, 500, 600, 700],
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
