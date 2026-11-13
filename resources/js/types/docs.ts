/** Felul unei pagini de documentație: prezentarea generală, README-ul generat sau pagina unui modul. */
export type DocumentKind = 'overview' | 'readme' | 'module';

/**
 * Un capitol din meniul documentației, așa cum îl trimite serverul (App\Documentation\DocumentNavigation).
 * Același format îl folosește orice ecran care arată capitolele.
 */
export type DocumentLink = {
    slug: string;
    /** „Despre proiect”, „README generat” sau calea modulului, de exemplu „app/Http”. */
    title: string;
    kind: DocumentKind;
    /** Folderul de sus al unui modul („app” pentru „app/Http”); `null` pentru celelalte pagini. */
    group: string | null;
    /** Adevărat când pagina e depășită (codul s-a schimbat și Claude n-a putut-o rescrie) sau e scrisă automat în locul celei cu AI. */
    is_stale: boolean;
    /** Adresa paginii, de exemplu „/repositories/1/docs/app-http”. */
    href: string;
};

/** O pagină de documentație deschisă: capitolul, textul Markdown și cine l-a scris (App\Http\Resources\DocumentResource). */
export type DocumentPage = DocumentLink & {
    markdown: string;
    /** „ai” = scrisă de Claude; „template” = scrisă automat din analiza codului, fără AI. */
    writer: 'ai' | 'template';
    model: string | null;
    /** Cum a fost apelat Claude: „claude-code” (abonamentul) sau „claude” (API-ul). */
    ai_driver: string | null;
    input_tokens: number;
    output_tokens: number;
    /** Cât ar fi costat apelul pe API, în dolari; `null` când nu se știe. */
    cost_usd_equivalent: number | null;
    /** Commitul din care e scrisă pagina (legăturile spre GitHub arată la el). */
    source_sha: string | null;
    generated_at: string | null;
    stale_reason: string | null;
    /** Doar pentru README: adresa fișierului README.md de descărcat. */
    download_url: string | null;
    /** Doar pentru README: arhiva .zip cu README.md și folderul images/. */
    download_zip_url: string | null;
};

/** „Despre proiect” pe Prezentare: primele secțiuni ale prezentării generale (App\Http\Resources\OverviewSummaryResource). */
export type OverviewSummary = {
    markdown: string;
    /** Adresa documentației întregi. */
    url: string;
    is_stale: boolean;
};

/** One of the README's own pictures, copied by the app at the last processed commit (App\Documentation\ReadmeShowcase). */
export type ReadmePicture = {
    id: string;
    /** Address on this app (never the original one); ends in `?v=<commit>`. */
    url: string;
    width: number | null;
    height: number | null;
    /** The README's alt text; may be empty. */
    alt: string;
    /** The README section the picture appeared under, or `null`. */
    section: string | null;
};

/** The neighbours of a module in the import graph: at most 6 a side, the rest is only counted. */
export type ModuleNeighbour = {
    path: string;
    /** How many imports go along the arrow. */
    weight: number;
    /** The module's documentation page, or `null` when it has none. */
    href: string | null;
};

export type ModuleNeighbourhood = {
    module: string;
    incoming: ModuleNeighbour[];
    outgoing: ModuleNeighbour[];
    more_incoming: number;
    more_outgoing: number;
};

/** One update: the commits it covered and the pages it rewrote (a processing run, App\Documentation\DocumentVisuals). */
export type DocumentUpdate = {
    from_sha: string | null;
    to_sha: string | null;
    files_changed: number;
    documents_regenerated: number;
    documents_kept: number;
    finished_at: string | null;
};

/**
 * The pictures around a page's text, sent by the server (App\Documentation\DocumentVisuals). Each part exists only
 * when its data does: a missing picture is simply absent.
 */
export type DocumentVisuals = {
    /** The commit the pictures were made for. */
    commit: string | null;
    /** The top of the capture of the repository's GitHub page. */
    capture: { url: string; width: number; height: number; captured_at: string | null } | null;
    pictures: ReadmePicture[];
    /** Ids of the pictures not already inside a shown description section: the gallery. */
    gallery: string[];
    /** The README's own descriptive sections (overview: the first two); `null` when there are none. */
    description: { sections: { heading: string | null; level: number; markdown: string; pictures: string[] }[]; total: number; readme_href: string } | null;
    facts: { languages: { name: string; share: number }[]; files: number; lines: number; modules: number; commits: number } | null;
    architecture: { type: string; title: string; mermaid: string; caption: string | null; href: string } | null;
    neighbourhood: ModuleNeighbourhood | null;
    updates: DocumentUpdate[];
};
