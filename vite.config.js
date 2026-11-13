import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

/** Bibliotecile care transformă Markdown în elemente React (react-markdown și tot lanțul lui unified). */
const MARKDOWN_PACKAGES =
    /node_modules[\\/](react-markdown|remark-[^\\/]+|rehype-[^\\/]+|micromark[^\\/]*|mdast-[^\\/]+|hast-[^\\/]+|unist-[^\\/]+|unified|vfile[^\\/]*|property-information|space-separated-tokens|comma-separated-tokens|decode-named-character-reference|character-entities[^\\/]*|devlop|trim-lines|bail|is-plain-obj|trough|html-url-attributes|ccount|markdown-table|zwitch|longest-streak|style-to-js|style-to-object|inline-style-parser|estree-util-is-identifier-name)[\\/]/;

/** Codul spațiului de lucru al unui repository: aduce cuprinsul, asistentul și paginile documentației. */
const WORKSPACE_FILES = /resources[\\/]js[\\/](layouts[\\/](workspace|repository)-layout|components[\\/](workspace(?![\\/]icons)|assistant|docs|repository)[\\/])/;

/** Restul codului comun al aplicației: funcțiile, hook-urile, antetul și componentele simple (fără dicționare și pagini). */
const SHELL_FILES = /resources[\\/]js[\\/](hooks[\\/]|lib[\\/](?!diagram-icons)|layouts[\\/]app-layout|components[\\/][^\\/]+\.tsx|components[\\/]workspace[\\/]icons)/;

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            // Fonturile aplicației, descărcate la build și servite de la noi. „latin-ext” aduce ă, ș și ț:
            // fără el, browserul le desena cu un font al sistemului, în mijlocul cuvintelor.
            // Preîncărcăm doar fonturile primului ecran; restul vin când e nevoie de ele.
            fonts: [
                // Mona Sans: tot textul interfeței (titluri, text, butoane, logo), într-o singură familie.
                bunny('Mona Sans', {
                    weights: [400, 500, 600, 700],
                    subsets: ['latin', 'latin-ext'],
                    preload: [{ weight: 400 }, { weight: 600 }],
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
    build: {
        rolldownOptions: {
            output: {
                // Biblioteca care trebuie fiecărei pagini (React și Inertia) stă în câte un fișier stabil, cu hash
                // propriu: browserul îl ține în cache și între versiuni ale aplicației, cât timp ele nu se schimbă.
                // Fără asta, Rolldown le punea în fișierul primului modul care le folosea (cu dicționarele în el).
                // Bibliotecile grele (Mermaid, ELK, Cytoscape, KaTeX) rămân în fișierele lor, aduse doar când
                // se desenează o diagramă sau o formulă.
                //
                // Fără alte reguli, Rolldown rupe codul comun în zeci de fișiere de 1 KB, iar fiecare cere un drum
                // până la server (serverul PHP de dezvoltare răspunde pe rând). Grupăm deci și codul comun:
                // „markdown” (cititorul de Markdown), „workspace” (spațiul de lucru al unui repository) și „shell”
                // (restul: componentele, funcțiile și hook-urile folosite de orice pagină). Paginile și dicționarele
                // rămân fișiere separate, ca să se aducă doar cele de care e nevoie.
                codeSplitting: {
                    groups: [
                        { name: 'react', test: /node_modules[\\/](react|react-dom|scheduler)[\\/]/, priority: 5 },
                        { name: 'inertia', test: /node_modules[\\/](@inertiajs|axios|lodash-es|es-toolkit|laravel-precognition|nprogress|qs)[\\/]/, priority: 4 },
                        { name: 'markdown', test: MARKDOWN_PACKAGES, priority: 3, includeDependenciesRecursively: false },
                        { name: 'workspace', test: WORKSPACE_FILES, priority: 2, includeDependenciesRecursively: false },
                        { name: 'shell', test: SHELL_FILES, priority: 1 },
                    ],
                },
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
