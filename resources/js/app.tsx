import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import WorkspaceLayout from '@/layouts/workspace-layout';

const appName = import.meta.env.VITE_APP_NAME || 'GitChronicle';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name) => {
        const page = await resolvePageComponent<{ default: ComponentType }>(
            `./pages/${name}.tsx`,
            import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx'),
        );

        return page.default;
    },
    // Bara subțire de sus, care apare când un răspuns întârzie peste 200 ms, în culoarea de accent a temei.
    progress: {
        color: 'var(--color-accent)',
        delay: 200,
        showSpinner: false,
    },
    defaults: {
        // Trecerea lină dintre pagini (View Transitions API, animată din resources/css/app.css), pornită o singură
        // dată, pentru orice navigare. Rămân fără ea cererile din fundal: reîmprospătarea automată cât se procesează
        // un repository (async) și reîncărcările parțiale (only / except), care nu schimbă pagina.
        // O legătură care își dă propria funcție viewTransition o păstrează.
        visitOptions: (_href, options) => {
            const isBackgroundRequest = options.async || (options.only?.length ?? 0) > 0 || (options.except?.length ?? 0) > 0;

            if (isBackgroundRequest || options.viewTransition) {
                return {};
            }

            return { viewTransition: true };
        },
    },
    // Paginile unui repository se deschid în spațiul de lucru (cuprins, elemente, asistent). Fiind layout-ul
    // implicit, Inertia îl păstrează montat între ele: cuprinsul și asistentul nu pornesc de la zero la fiecare capitol.
    layout: (name) => (name.startsWith('repositories/') ? WorkspaceLayout : null),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
