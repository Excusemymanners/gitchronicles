//
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
    // Paginile unui repository se deschid în spațiul de lucru (cuprins, elemente, asistent). Fiind layout-ul
    // implicit, Inertia îl păstrează montat între ele: cuprinsul și asistentul nu pornesc de la zero la fiecare capitol.
    layout: (name) => (name.startsWith('repositories/') ? WorkspaceLayout : null),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
