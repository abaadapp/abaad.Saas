import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            // مدخلان: لوحةُ التاجر، وموقعُ متجره.
            //
            // و`site.tsx` لا يمرّ بـ`app.tsx`: تلك حزمةُ اللوحة — Inertia
            // وقوائمُها وجداولُها — وهي ثلث ميغابايت. وزبونٌ يفتح رابطًا من
            // واتساب لينظر إلى منتجٍ لا شأن له بشيءٍ منها.
            //
            // وحزمة app.js القديمة (Alpine + ApexCharts + Sortable) لم يبقَ
            // لها مستهلك بعد تحويل صفحتَي المصادقة، فحُذفت.
            input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/site.tsx'],
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
