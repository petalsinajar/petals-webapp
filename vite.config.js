import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny, google } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/landing.css', 'resources/js/landing.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Landing page. Downloaded at build time and served from our own domain.
                google('Fredoka', { weights: [500, 600, 700], preload: [{ weight: 600 }] }),
                google('DM Sans', { weights: [400, 500, 700], preload: [{ weight: 400 }] }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
