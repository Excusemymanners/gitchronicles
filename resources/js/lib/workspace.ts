import { router, usePage } from '@inertiajs/react';
import { useCallback, useSyncExternalStore } from 'react';
import type { WorkspaceGroup, WorkspaceGroupKey } from '@/types';

/** Capitolul deschis acum: grupul din care face parte, eticheta și adresa lui. */
export type ActiveChapter = {
    groupKey: WorkspaceGroupKey;
    groupTitle: string;
    label: string;
    href: string;
};

/** Desparte o adresă în cale și ancoră: „/repositories/1/diagrams#straturi” → ['/repositories/1/diagrams', '#straturi']. */
export function splitHref(href: string): [path: string, hash: string] {
    const hashStart = href.indexOf('#');
    const withoutHash = hashStart === -1 ? href : href.slice(0, hashStart);
    const queryStart = withoutHash.indexOf('?');

    return [queryStart === -1 ? withoutHash : withoutHash.slice(0, queryStart), hashStart === -1 ? '' : href.slice(hashStart)];
}

/**
 * Capitolul care se potrivește cu pagina deschisă. Întâi căutăm aceeași cale și aceeași ancoră
 * (de exemplu „Straturi” pentru /diagrams#straturi); fără ancoră, capitolul fără ancoră al paginii
 * („Prezentare generală”) sau, dacă pagina are doar secțiuni, prima ei secțiune.
 */
export function findActiveChapter(groups: WorkspaceGroup[], path: string, hash: string): ActiveChapter | null {
    const chapters: ActiveChapter[] = groups.flatMap((group) => [
        ...group.chapters.map((chapter) => ({ groupKey: group.key, groupTitle: group.title, label: chapter.label, href: chapter.href })),
        ...group.folders.flatMap((folder) =>
            folder.modules.map((module) => ({ groupKey: group.key, groupTitle: group.title, label: module.path, href: module.href })),
        ),
    ]);

    const onThisPage = chapters.filter((chapter) => splitHref(chapter.href)[0] === path);

    return (
        (hash !== '' ? onThisPage.find((chapter) => splitHref(chapter.href)[1] === hash) : undefined) ??
        onThisPage.find((chapter) => splitHref(chapter.href)[1] === '') ??
        onThisPage[0] ??
        null
    );
}

function subscribeToLocation(onChange: () => void): () => void {
    window.addEventListener('hashchange', onChange);
    window.addEventListener('popstate', onChange);
    const removeNavigateListener = router.on('navigate', onChange);

    return () => {
        window.removeEventListener('hashchange', onChange);
        window.removeEventListener('popstate', onChange);
        removeNavigateListener();
    };
}

/**
 * Ancora din adresa paginii („#straturi”), actualizată la fiecare schimbare. O citim din window.location,
 * pentru că o legătură obișnuită spre o secțiune a aceleiași pagini schimbă doar ancora, fără o vizită Inertia.
 */
export function useLocationHash(): string {
    return useSyncExternalStore(subscribeToLocation, () => window.location.hash);
}

/** Capitolul deschis acum, din cuprinsul primit de la server și adresa paginii. */
export function useActiveChapter(): ActiveChapter | null {
    const { props, url } = usePage<{ workspace?: { chapters: WorkspaceGroup[] } }>();
    const hash = useLocationHash();

    return findActiveChapter(props.workspace?.chapters ?? [], splitHref(url)[0], hash);
}

/**
 * Dacă ecranul se potrivește cu o regulă CSS (de exemplu „(min-width: 80rem)”), urmărită la redimensionare.
 * Așa montăm un panou într-un singur loc (coloană sau sub conținut), nu de două ori ascuns cu CSS.
 */
export function useMediaQuery(query: string): boolean {
    const subscribe = useCallback(
        (onChange: () => void) => {
            const mediaQueryList = window.matchMedia(query);
            mediaQueryList.addEventListener('change', onChange);

            return () => mediaQueryList.removeEventListener('change', onChange);
        },
        [query],
    );

    return useSyncExternalStore(subscribe, () => window.matchMedia(query).matches);
}

/**
 * Scrolls the nearest scrollable ancestor (a column, the tab row) just enough to show the element. Unlike
 * scrollIntoView(), this does not move the browser's keyboard starting point, so the first Tab on a page still
 * goes to "Skip to content" instead of the element after the open tab.
 */
export function revealInScrollParent(element: HTMLElement | null, axis: 'x' | 'y'): void {
    let container = element?.parentElement ?? null;

    while (container && !/(auto|scroll)/.test(getComputedStyle(container)[axis === 'x' ? 'overflowX' : 'overflowY'])) {
        container = container.parentElement;
    }

    if (!element || !container) {
        return;
    }

    const box = element.getBoundingClientRect();
    const view = container.getBoundingClientRect();
    const [start, end, viewStart, viewEnd] = axis === 'x' ? [box.left, box.right, view.left, view.right] : [box.top, box.bottom, view.top, view.bottom];
    const margin = 16;

    if (start < viewStart) {
        container[axis === 'x' ? 'scrollLeft' : 'scrollTop'] -= viewStart - start + margin;
    } else if (end > viewEnd) {
        container[axis === 'x' ? 'scrollLeft' : 'scrollTop'] += end - viewEnd + margin;
    }
}
