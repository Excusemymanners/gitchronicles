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
    /** Adevărat când pagina e scrisă automat în locul celei cu AI (Claude n-a putut-o scrie). */
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
};

/** „Despre proiect” pe Prezentare: primele secțiuni ale prezentării generale (App\Http\Resources\OverviewSummaryResource). */
export type OverviewSummary = {
    markdown: string;
    /** Adresa documentației întregi. */
    url: string;
    is_stale: boolean;
};
