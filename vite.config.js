import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            // Fonturile aplicației, descărcate la build și servite de la noi. „latin-ext” aduce ă, ș și ț:
            // fără el, browserul le desena cu un font al sistemului, în mijlocul cuvintelor.
            // Preîncărcăm doar fonturile primului ecran; restul vin când e nevoie de ele.
            fonts: [
                // Textul obișnuit.
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 400 }],
                }),
                // Titlurile și numele din logo.
                bunny('Mona Sans', {
                    weights: [600, 700],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 700 }],
                }),
                // Cod, SHA-uri și căi de fișiere.
                bunny('JetBrains Mono', {
                    weights: [400, 500],
                    subsets: ['latin', 'latin-ext'],
                    preload: false,
                }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
