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

/**
 * Prop-urile primite de fiecare pagină a unui repository (toate tab-urile), de la
 * RendersRepositoryPage în Laravel. RepositoryLayout le citește pentru antet.
 */
export type RepositoryPageProps = {
    repository: RepositoryItem;
    /** Adevărat doar pentru utilizatorul autentificat care a adăugat acest repository. */
    can_delete: boolean;
    /** Adevărat pentru orice utilizator autentificat, când procesarea a eșuat. */
    can_retry: boolean;
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
