import { useSyncExternalStore } from 'react';

/**
 * Desenarea diagramelor Mermaid în browser (Faza 7).
 *
 * Mermaid (cu layout-ul ELK, inclus în Mermaid 12) și logo-urile tehnologiilor se încarcă doar când
 * o pagină chiar desenează o diagramă (import dinamic), ca restul paginilor să rămână mici.
 *
 * - securityLevel „strict”: etichetele nu pot rula cod și nu pot deschide legături;
 * - culorile vin din tema paginii (tokenii --color-* când există, altfel aceleași valori scrise aici);
 * - clasele C4 ale diagramelor primesc culorile paletei, în ambele teme (vezi DARK_CLASS_STYLES și LIGHT_CLASS_STYLES);
 * - diagramele se desenează pe rând, nu în paralel: Mermaid ține o stare globală (configurația).
 */

export type DiagramTheme = 'light' | 'dark';

type Mermaid = (typeof import('mermaid'))['default'];

/** Un pachet de iconițe, în forma cerută de mermaid.registerIconPacks(). */
type IconPack = Parameters<Mermaid['registerIconPacks']>[0][number];

/** Evenimentul trimis de comutatorul de temă (tema întunecată) pe `window`. */
const THEME_CHANGE_EVENT = 'gitchronicle-theme-change';

/**
 * The theme's colours, used when the page does not have the --color-* tokens yet (the same values as in
 * resources/css/app.css, Brand 3 — anthracite).
 */
const FALLBACK_COLORS: Record<DiagramTheme, Record<string, string>> = {
    light: {
        canvas: '#ffffff',
        surface: '#f6f8fa',
        'surface-2': '#eff2f5',
        'surface-raised': '#ffffff',
        line: '#d1d9e0',
        'line-strong': '#818b98',
        ink: '#1f2328',
        'ink-muted': '#59636e',
        'ink-subtle': '#656d76',
        accent: '#0969da',
        shadow: 'rgb(31 35 40 / 0.08)',
    },
    dark: {
        canvas: '#0d1117',
        surface: '#151b23',
        'surface-2': '#1c2128',
        'surface-raised': '#212830',
        line: '#30363d',
        'line-strong': '#656c76',
        ink: '#f0f6fc',
        'ink-muted': '#9198a1',
        'ink-subtle': '#8b949e',
        accent: '#4493f8',
        shadow: 'rgb(1 4 9 / 0.5)',
    },
};

/**
 * The light tile a technology's logo sits on (in a diagram node and in the technology list), in both themes:
 * many logos are black (GitHub, Next.js), so they need a light ground even on the dark page.
 */
export const LOGO_TILE_FILL = '#f6f8fa';

/**
 * The C4 classes, drawn in the page's palette: neutral boxes, and the accent only where the diagram wants the
 * eye (the system being described, the components inside a container). Mermaid's classDef takes literal colours,
 * so these are the token values of each theme written out. The saved Mermaid code keeps the server's own colours
 * (it looks the same when copied into another editor); on the page, codeForTheme() swaps in these.
 */
export const DARK_CLASS_STYLES: Record<string, string> = {
    person: 'fill:#212830,stroke:#9198a1,color:#f0f6fc',
    system: 'fill:#212830,stroke:#4493f8,stroke-width:1.5px,color:#f0f6fc',
    container: `fill:${LOGO_TILE_FILL}`,
    containerBox: 'fill:#1c2128,stroke:#656c76,color:#f0f6fc',
    component: 'fill:#212830,stroke:#4493f8,color:#f0f6fc',
    external: 'fill:#eff2f5',
    externalBox: 'fill:#151b23,stroke:#3d444d,color:#9198a1',
    more: 'fill:none,stroke:#3d444d,stroke-dasharray:4 3,color:#9198a1',
    boundary: 'fill:none,stroke:#8b949e,stroke-dasharray:6 4',
    tool: 'fill:none,stroke:#9198a1,stroke-dasharray:5 4,color:#f0f6fc',
    mapPart: 'fill:#212830,stroke:#656c76,color:#f0f6fc',
    mapInterface: 'fill:#212830,stroke:#656c76,color:#f0f6fc',
    mapWorker: 'fill:#212830,stroke:#656c76,color:#f0f6fc',
    mapData: 'fill:#1c2128,stroke:#656c76,color:#f0f6fc',
    mapExternal: 'fill:none,stroke:#9198a1,stroke-width:1.5px,color:#f0f6fc',
    mapTool: 'fill:none,stroke:#9198a1,stroke-dasharray:5 4,color:#f0f6fc',
};

/** The same classes on the light page. */
export const LIGHT_CLASS_STYLES: Record<string, string> = {
    person: 'fill:#ffffff,stroke:#59636e,color:#1f2328',
    system: 'fill:#ffffff,stroke:#0969da,stroke-width:1.5px,color:#1f2328',
    container: `fill:${LOGO_TILE_FILL}`,
    containerBox: 'fill:#f6f8fa,stroke:#818b98,color:#1f2328',
    component: 'fill:#ffffff,stroke:#0969da,color:#1f2328',
    external: 'fill:#eff2f5',
    externalBox: 'fill:#eff2f5,stroke:#d1d9e0,color:#59636e',
    more: 'fill:none,stroke:#afb8c1,stroke-dasharray:4 3,color:#59636e',
    boundary: 'fill:none,stroke:#656d76,stroke-dasharray:6 4',
    tool: 'fill:none,stroke:#59636e,stroke-dasharray:5 4,color:#1f2328',
    mapPart: 'fill:#ffffff,stroke:#818b98,color:#1f2328',
    mapInterface: 'fill:#ffffff,stroke:#818b98,color:#1f2328',
    mapWorker: 'fill:#ffffff,stroke:#818b98,color:#1f2328',
    mapData: 'fill:#f6f8fa,stroke:#818b98,color:#1f2328',
    mapExternal: 'fill:none,stroke:#59636e,stroke-width:1.5px,color:#1f2328',
    mapTool: 'fill:none,stroke:#59636e,stroke-dasharray:5 4,color:#1f2328',
};

/** The main path of the big picture: the accent, thicker (the server writes the light one). */
export const MAIN_PATH_STYLES: Record<DiagramTheme, string> = {
    light: 'stroke:#0969da,stroke-width:2.5px,color:#0969da',
    dark: 'stroke:#4493f8,stroke-width:2.5px,color:#4493f8',
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
        fontFamily: "'Mona Sans', ui-sans-serif, system-ui, sans-serif",
        themeVariables: {
            fontFamily: "'Mona Sans', ui-sans-serif, system-ui, sans-serif",
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
            noteBkgColor: color('surface-2'),
            noteTextColor: color('ink'),
            noteBorderColor: color('line-strong'),
            actorBkg: color('surface-raised'),
            actorBorder: color('line-strong'),
            actorTextColor: color('ink'),
            signalColor: color('ink-muted'),
            signalTextColor: color('ink'),
            sequenceNumberColor: color('canvas'),
            // Mermaid 12 draws its "neo" nodes with a light grey shadow (meant for a white page); here the shadow
            // is the page's own (a soft dark one in both themes), so boxes lift a little instead of glowing.
            dropShadow: `drop-shadow(0 1px 2px ${color('shadow')})`,
        },
        // Sequences (the flows): bigger text and no second row of participants at the bottom, so they read at page size.
        sequence: { actorFontSize: 15, messageFontSize: 15, noteFontSize: 14, actorMargin: 40, width: 140, boxMargin: 8, mirrorActors: false },
    });
}

/**
 * The diagram's code for the given theme: every known C4 class gets the style of the page's palette in that theme
 * (see DARK_CLASS_STYLES and LIGHT_CLASS_STYLES).
 */
export function codeForTheme(code: string, theme: DiagramTheme): string {
    const styles = theme === 'dark' ? DARK_CLASS_STYLES : LIGHT_CLASS_STYLES;

    return code
        .replace(/^(\s*classDef\s+)([\w-]+)(\s+).*$/gm, (line, start: string, name: string, space: string) =>
            name in styles ? `${start}${name}${space}${styles[name]}` : line,
        )
        .replace(/^(\s*linkStyle\s+[\d,]+\s+).*$/gm, (_line, start: string) => `${start}${MAIN_PATH_STYLES[theme]}`);
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
