import type { Activity, Hotspot } from '@/types/history';
import type { MediaStatus } from '@/types/media';

/** Utilizatorul autentificat, așa cum îl trimite serverul în `auth.user`. */
export type User = {
    id: number;
    name: string;
    email: string;
};

/**
 * Etapele prin care trece un repository: în așteptare → se clonează → se analizează codul →
 * se scrie documentația → gata, sau eroare la oricare pas. Un repository gata trece prin
 * „se actualizează” când aducem documentația la zi.
 */
export type RepositoryStatus = 'pending' | 'cloning' | 'analyzing' | 'documenting' | 'ready' | 'updating' | 'failed';

/** Un repository, în forma JSON primită de la server (aceeași pe listă și pe pagina lui). */
export type RepositoryItem = {
    id: number;
    url: string;
    owner: string;
    name: string;
    /** „proprietar/nume”, de exemplu „laravel/framework”. */
    full_name: string;
    /** O descriere scurtă a proiectului; `null` până o aflăm. */
    description: string | null;
    status: RepositoryStatus;
    /** Statusul tradus în română, gata de afișat. */
    status_label: string;
    /** Adevărat cât timp procesarea mai are de lucru: pagina se reîmprospătează singură. */
    is_processing: boolean;
    /** Ramura principală, de exemplu „main”; `null` până la prima clonare. */
    default_branch: string | null;
    /** SHA-ul ultimului commit procesat; `null` cât timp repository-ul nu a fost clonat. */
    last_sha: string | null;
    /** Ce n-a mers, pe înțelesul oricui; există doar când statusul e „failed”. */
    error: string | null;
    /** Datele etapelor, în format ISO 8601; `null` până se termină etapa. */
    cloned_at: string | null;
    analyzed_at: string | null;
    documented_at: string | null;
    /** Data adăugării, în format ISO 8601. */
    created_at: string;
};

/**
 * Rezultatul analizei codului (tree-sitter), afișat în secțiunea „Structura codului”.
 * Un modul este un folder care grupează fișiere, de exemplu „app/Http”; „/” e folderul rădăcină.
 */
export type CodeStructure = {
    file_count: number;
    line_count: number;
    module_count: number;
    language_count: number;
    /** Limbajele, cel cu cele mai multe linii primul; „Altele” = fișierele cu limbaj necunoscut. */
    languages: { name: string; file_count: number; line_count: number }[];
    /** Toate modulele, în ordinea căii. */
    modules: { id: number; path: string; name: string; file_count: number; line_count: number }[];
    /** Cele mai puternice dependențe: fișierele din `from` importă de `weight` ori fișiere din `to`. */
    dependencies: { from: string; to: string; weight: number }[];
    /** Câte dependențe sunt în total (lista de mai sus le arată doar pe cele mai puternice). */
    dependency_count: number;
};

/** Un capitol din cuprins: o pagină sau o secțiune a unei pagini (partea de după „#”). */
export type WorkspaceChapter = {
    label: string;
    href: string;
    /** Un număr afișat lângă capitol (de exemplu câte commit-uri); `null` = nimic. */
    count: number | null;
};

/** Un modul în cuprins. `label` e numele din folderul lui („Http” în „app”); `href` duce la pagina lui. */
export type WorkspaceModule = {
    id: number;
    path: string;
    label: string;
    file_count: number;
    href: string;
};

/** Un folder de pe primul nivel („app”) cu modulele lui; „/” e rădăcina proiectului. */
export type WorkspaceModuleFolder = {
    name: string;
    file_count: number;
    modules: WorkspaceModule[];
};

export type WorkspaceGroupKey = 'docs' | 'architecture' | 'modules' | 'history' | 'media';

/** Un grup de capitole din cuprins. Doar grupul „Module” are foldere și un număr lângă titlu. */
export type WorkspaceGroup = {
    key: WorkspaceGroupKey;
    title: string;
    count: number | null;
    chapters: WorkspaceChapter[];
    folders: WorkspaceModuleFolder[];
};

/** Cardurile din panoul „Elemente”: fiecare e `null` până când datele lui există. */
export type WorkspaceElements = {
    /** Diagrama previzualizată (straturile, dacă există), cu codul Mermaid. */
    architecture: { title: string; type: string; mermaid: string; href: string } | null;
    /** Video-ul de prezentare: starea lui și, când e gata, posterul și durata. `null` până e pornit prima dată. */
    video: { href: string; status: MediaStatus; status_label: string; poster: string | null; duration_ms: number | null } | null;
    /** Slide-urile: starea lor și, când sunt gata, câte sunt. `null` până sunt pornite prima dată. */
    slides: { href: string; status: MediaStatus; status_label: string; slide_count: number | null } | null;
    /** Activitatea ultimelor 12 luni, aceeași ca în tab-ul „Istoric” (HistoryStatistics). */
    activity: (Activity & { href: string }) | null;
    /** Primele 5 „Zone fierbinți”, fără fișiere lock, biblioteci și fișiere generate. */
    hotspots: { files: Hotspot[]; href: string } | null;
};

/** Un fragment de cod pe care se bazează un răspuns, cu liniile lui exacte. */
export type AssistantCitation = {
    /** Numărul din răspuns: [1], [2], ... */
    index: number;
    path: string;
    start_line: number;
    end_line: number;
    /** Clasa sau funcția fragmentului, de exemplu „LoginController.store”; `null` pentru o fereastră de linii. */
    symbol_name: string | null;
    /** Liniile pe GitHub, la commitul indexat; `null` dacă nu știm commitul. */
    url: string | null;
    /** Adevărat dacă răspunsul chiar citează fragmentul. */
    cited: boolean;
};

/** Răspunsul serverului la o întrebare (POST /repositories/{id}/assistant). */
export type AssistantReply = {
    answer_markdown: string;
    citations: AssistantCitation[];
    /** „ai”: scris de Claude din fragmente; „retrieval”: doar fragmentele găsite, fără AI. */
    mode: 'ai' | 'retrieval';
    /** De ce răspunsul e fără AI (AI oprit sau Claude indisponibil); `null` altfel. */
    notice: string | null;
    usage: {
        input_tokens: number;
        output_tokens: number;
        cost_usd_equivalent: number | null;
        model: string;
        driver: string;
    } | null;
};

/**
 * Spațiul de lucru al unui repository (WorkspaceResource în Laravel): cuprinsul din stânga,
 * cardurile din dreapta și întrebările de pornire ale asistentului. Totul vine din date reale.
 */
export type Workspace = {
    chapters: WorkspaceGroup[];
    elements: WorkspaceElements;
    assistant: {
        /** Adevărat după ce codul a fost împărțit în bucăți căutabile (Faza 8). */
        is_indexed: boolean;
        /** Până la trei întrebări despre module reale, pentru butoanele de deasupra câmpului. */
        starters: string[];
    };
};

/**
 * Prop-urile primite de fiecare pagină a unui repository, de la RendersRepositoryPage în Laravel.
 * WorkspaceLayout le citește pentru bara de sus, cuprins, elemente și asistent.
 */
export type RepositoryPageProps = {
    repository: RepositoryItem;
    /** Adevărat doar pentru utilizatorul autentificat care a adăugat acest repository. */
    can_delete: boolean;
    /** Adevărat pentru orice utilizator autentificat, când procesarea a eșuat. */
    can_retry: boolean;
    workspace: Workspace;
};

/**
 * Tipuri globale pentru Inertia: prop-urile trimise pe fiecare pagină
 * (HandleInertiaRequests::share) și mesajele flash (Inertia::flash).
 * Așa, `usePage().props.auth` și `usePage().flash.success` au tipuri corecte în toate paginile.
 */
declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            auth: {
                user: User | null;
            };
        };
        /** Un mesaj de succes (verde), de informare (indigo) sau de eroare (roșu). */
        flashDataType: {
            success?: string;
            info?: string;
            error?: string;
        };
    }
}
