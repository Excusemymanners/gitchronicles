import { useSyncExternalStore } from 'react';

/**
 * Desenarea diagramelor Mermaid în browser (Faza 7).
 *
 * Mermaid (cu layout-ul ELK, inclus în Mermaid 12) și logo-urile tehnologiilor se încarcă doar când
 * o pagină chiar desenează o diagramă (import dinamic), ca restul paginilor să rămână mici.
 *
 * - securityLevel „strict”: etichetele nu pot rula cod și nu pot deschide legături;
 * - culorile vin din tema paginii (tokenii --color-* când există, altfel aceleași valori scrise aici);
 * - în tema întunecată, clasele C4 ale diagramelor primesc variantele lor întunecate (vezi DARK_CLASS_STYLES);
 * - diagramele se desenează pe rând, nu în paralel: Mermaid ține o stare globală (configurația).
 */

export type DiagramTheme = 'light' | 'dark';

type Mermaid = (typeof import('mermaid'))['default'];

/** Un pachet de iconițe, în forma cerută de mermaid.registerIconPacks(). */
type IconPack = Parameters<Mermaid['registerIconPacks']>[0][number];

/** Evenimentul trimis de comutatorul de temă (tema întunecată) pe `window`. */
const THEME_CHANGE_EVENT = 'gitchronicle-theme-change';

/**
 * Culorile temei, folosite când pagina nu are încă tokenii --color-* (aceleași valori ca în app.css-ul temei).
 */
const FALLBACK_COLORS: Record<DiagramTheme, Record<string, string>> = {
    light: {
        canvas: '#f9fafb',
        surface: '#ffffff',
        'surface-2': '#f3f4f6',
        'surface-raised': '#ffffff',
        line: '#e5e7eb',
        'line-strong': '#d1d5db',
        ink: '#111827',
        'ink-muted': '#4b5563',
        'ink-subtle': '#6b7280',
    },
    dark: {
        canvas: '#0a0e1a',
        surface: '#111727',
        'surface-2': '#172036',
        'surface-raised': '#1a2440',
        line: '#253049',
        'line-strong': '#4a5675',
        ink: '#e8ebf4',
        'ink-muted': '#a3acc2',
        'ink-subtle': '#838ca4',
    },
};

/**
 * Stilurile claselor C4 în tema întunecată. Codul Mermaid salvat are variantele luminoase
 * (așa arată la fel și copiat în alt editor); pe pagină, în tema întunecată, le înlocuim cu acestea.
 * O cutie albastru-închis primește un contur deschis, ca să se vadă pe fundalul bleumarin.
 */
export const DARK_CLASS_STYLES: Record<string, string> = {
    person: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    system: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    containerBox: 'fill:#1e3a8a,stroke:#60a5fa,color:#e8ebf4',
    component: 'fill:#1d4ed8,stroke:#60a5fa,color:#ffffff',
    externalBox: 'fill:#26304a,stroke:#4a5675,color:#e8ebf4',
    more: 'fill:none,stroke:#4a5675,stroke-dasharray:4 3,color:#a3acc2',
    boundary: 'fill:none,stroke:#838ca4,stroke-dasharray:6 4',
};

/** Stilurile claselor C4 în tema luminoasă: aceleași ca în codul scris de server (ArchitectureDiagramBuilder). */
export const LIGHT_CLASS_STYLES: Record<string, string> = {
    person: 'fill:#08427b,stroke:#073b6f,color:#ffffff',
    system: 'fill:#1e3a8a,stroke:#1e3a8a,color:#ffffff',
    container: 'fill:#e8f1fb',
    containerBox: 'fill:#1e3a8a,stroke:#1e3a8a,color:#ffffff',
    component: 'fill:#2563eb,stroke:#1d4ed8,color:#ffffff',
    external: 'fill:#e5e7eb',
    externalBox: 'fill:#e5e7eb,stroke:#9ca3af,color:#111827',
    more: 'fill:none,stroke:#9ca3af,stroke-dasharray:4 3,color:#6b7280',
    boundary: 'fill:none,stroke:#6b7280,stroke-dasharray:6 4',
};

/** Tema afișată acum: clasa „dark” de pe <html>. */
export function currentDiagramTheme(): DiagramTheme {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
}

/** Ne abonăm la schimbarea clasei de pe <html> și la evenimentul comutatorului de temă. */
function subscribeToTheme(onChange: () => void): () => void {
    const observer = new MutationObserver(onChange);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener(THEME_CHANGE_EVENT, onChange);

    return () => {
        observer.disconnect();
        window.removeEventListener(THEME_CHANGE_EVENT, onChange);
    };
}

/** Tema curentă, pentru o componentă care trebuie redesenată când tema se schimbă. */
export function useDiagramTheme(): DiagramTheme {
    return useSyncExternalStore(subscribeToTheme, currentDiagramTheme, () => 'light');
}

/** Culoarea unui token al temei (--color-…), sau valoarea lui de rezervă. */
function themeColor(token: string, theme: DiagramTheme): string {
    const value = getComputedStyle(document.documentElement).getPropertyValue(`--color-${token}`).trim();

    return value || FALLBACK_COLORS[theme][token];
}

let mermaidPromise: Promise<Mermaid> | null = null;

/** Încarcă Mermaid și logo-urile o singură dată, la prima diagramă. */
function loadMermaid(): Promise<Mermaid> {
    mermaidPromise ??= Promise.all([import('mermaid'), import('./diagram-icons.json')]).then(([module, icons]) => {
        const mermaid = module.default;
        // Doar logo-urile pe care le poate cere serverul (TechnologyCatalog), din pachetul Iconify „logos” (CC0).
        mermaid.registerIconPacks([{ name: 'logos', icons: icons.default } as IconPack]);

        return mermaid;
    });

    return mermaidPromise;
}

/** Configurația globală a lui Mermaid pentru tema dată (se pune înainte de fiecare desen). */
function configure(mermaid: Mermaid, theme: DiagramTheme): void {
    const color = (token: string) => themeColor(token, theme);

    mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'strict',
        // La o eroare, Mermaid ar lăsa în pagină desenul lui de eroare; afișăm noi un mesaj mai prietenos.
        suppressErrorRendering: true,
        theme: 'base',
        darkMode: theme === 'dark',
        fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
        themeVariables: {
            fontFamily: "'Instrument Sans', ui-sans-serif, system-ui, sans-serif",
            fontSize: '14px',
            background: color('surface'),
            mainBkg: color('surface-raised'),
            primaryColor: color('surface-raised'),
            primaryTextColor: color('ink'),
            primaryBorderColor: color('line-strong'),
            nodeBorder: color('line-strong'),
            nodeTextColor: color('ink'),
            secondaryColor: color('surface-2'),
            tertiaryColor: color('canvas'),
            textColor: color('ink'),
            titleColor: color('ink-muted'),
            lineColor: color('ink-subtle'),
            clusterBkg: color('canvas'),
            clusterBorder: color('line'),
            edgeLabelBackground: color('surface'),
        },
    });
}

/** Codul diagramei pentru tema dată: în tema întunecată, clasele C4 cunoscute primesc stilul întunecat. */
export function codeForTheme(code: string, theme: DiagramTheme): string {
    if (theme === 'light') {
        return code;
    }

    return code.replace(/^(\s*classDef\s+)([\w-]+)(\s+).*$/gm, (line, start: string, name: string, space: string) =>
        name in DARK_CLASS_STYLES ? `${start}${name}${space}${DARK_CLASS_STYLES[name]}` : line,
    );
}

let renderCount = 0;
let queue: Promise<unknown> = Promise.resolve();

/** Codul fără opțiunile ELK din configurație (blocul „elk:”), pentru a doua încercare. */
function withoutElkOptions(code: string): string {
    return code.replace(/^ {2}elk:\n(?: {4}.*\n)+/m, '');
}

/**
 * Desenează codul Mermaid și întoarce SVG-ul. Desenele așteaptă la rând unul după altul.
 * Dacă opțiunile ELK ale diagramei nu merg (ELK are câteva combinații care eșuează), încercăm
 * o dată fără ele: diagrama arată puțin altfel, dar apare. Altfel aruncă eroarea lui Mermaid.
 */
export function renderDiagram(code: string, theme: DiagramTheme): Promise<string> {
    const render = queue.then(async () => {
        const mermaid = await loadMermaid();
        await document.fonts.ready;
        configure(mermaid, theme);

        try {
            renderCount += 1;

            return (await mermaid.render(`gc-diagram-${renderCount}`, codeForTheme(code, theme))).svg;
        } catch (error) {
            const simpler = withoutElkOptions(code);

            if (simpler === code) {
                throw error;
            }

            renderCount += 1;

            return (await mermaid.render(`gc-diagram-${renderCount}`, codeForTheme(simpler, theme))).svg;
        }
    });

    queue = render.catch(() => undefined);

    return render;
}
