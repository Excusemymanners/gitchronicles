/** Utilizatorul autentificat, așa cum îl trimite serverul în `auth.user`. */
export type User = {
    id: number;
    name: string;
    email: string;
};

/** Etapele prin care trece un repository: adăugat → se clonează → clonat sau eșuat. */
export type RepositoryStatus = 'pending' | 'cloning' | 'cloned' | 'failed';

/** Un repository, în forma JSON primită de la server (aceeași pe listă și pe pagina lui). */
export type RepositoryItem = {
    id: number;
    url: string;
    owner: string;
    name: string;
    /** „proprietar/nume”, de exemplu „laravel/framework”. */
    full_name: string;
    status: RepositoryStatus;
    /** Statusul tradus în română, gata de afișat. */
    status_label: string;
    /** SHA-ul ultimului commit clonat; `null` cât timp repository-ul nu a fost clonat. */
    last_sha: string | null;
    /** Data adăugării, în format ISO 8601. */
    created_at: string;
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
