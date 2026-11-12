import type { ConsultingCounts, ConsultingStatus } from '@/types/consulting';
import type { Activity, Hotspot } from '@/types/history';
import type { MediaStatus } from '@/types/media';

/** Utilizatorul autentificat, așa cum îl trimite serverul în `auth.user`. */
export type User = {
    id: number;
    name: string;
    email: string;
    /** Adevărat pentru un administrator; doar pentru eticheta „admin”: drepturile vin de la server (`can_…`). */
    is_admin: boolean;
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

export type WorkspaceGroupKey = 'docs' | 'architecture' | 'modules' | 'history' | 'media' | 'consulting';

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
    /**
     * Consultanța: starea celui mai nou raport (`null` dacă nu a rulat încă) și, când e gata, problemele după gravitate.
     * Tot cardul e `null` cât timp repository-ul nu e gata și nu are niciun raport.
     */
    consulting: { href: string; status: ConsultingStatus | null; status_label: string | null; counts: ConsultingCounts | null } | null;
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
    /** Liniile citate, ca text (cel mult 12 linii, începând de la `start_line`), pentru previzualizare. */
    snippet: string;
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

/** Când a fost adus repository-ul la zi ultima dată și până la ce commit. */
export type LastUpdate = {
    sha: string;
    finished_at: string;
    /** Adevărat când ultima verificare nu a găsit commit-uri noi. */
    nothing_new: boolean;
};

/** Un artefact pe care GitChronicle îl face pentru un repository (RepositoryCompleteness în Laravel). */
export type ArtifactKey = 'structure' | 'history' | 'documentation' | 'diagrams' | 'assistant' | 'video' | 'slides';

/**
 * Starea unui artefact: există, lipsește deși ar trebui să existe, nu poate exista (repository-ul nu are
 * cod sursă) sau, doar pentru video și slide-uri, e în lucru ori a eșuat.
 */
export type ArtifactState = 'ready' | 'missing' | 'not_applicable' | 'working' | 'failed';

/** Ce are și ce îi lipsește unui repository, citit din datele lui reale. */
export type Completeness = {
    /** Adevărat când lipsește ceva din ce face procesarea (nu video-ul sau slide-urile). */
    is_incomplete: boolean;
    missing: ArtifactKey[];
    /** Lipsurile ca text, de exemplu „istoricul, documentația și diagramele”. */
    /** Adevărat când verificarea programată va porni singură o procesare completă. */
    heals_automatically: boolean;
    artifacts: Record<ArtifactKey, { state: ArtifactState; is_current: boolean }>;
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
    /** Adevărat pentru orice utilizator autentificat, când repository-ul e gata: poate cere „Verifică actualizări”. */
    can_check_updates: boolean;
    /** Adevărat pentru orice utilizator autentificat, când repository-ul e gata sau a eșuat: poate cere „Regenerează tot”. */
    can_regenerate: boolean;
    /** Ultima rulare terminată cu bine (reușită sau „nimic nou”); `null` până la prima. */
    last_update: LastUpdate | null;
    workspace: Workspace;
    /** Ce capitole există și ce lipsește, pentru stările goale ale capitolelor. */
    completeness: Completeness;
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
            /** Limba aleasă de server pentru această cerere ('en' sau 'ro'); lib/i18n.ts o urmează. */
            locale: string;
            /** Limbile comutatorului, fiecare cu numele în limba ei: { en: 'English', ro: 'Română' }. */
            locales: Record<string, string>;
        };
        /** Un mesaj de succes (verde), de informare (indigo) sau de eroare (roșu). */
        flashDataType: {
            success?: string;
            info?: string;
            error?: string;
        };
    }
}
