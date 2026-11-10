/**
 * Tipurile istoricului git al unui repository (tab-ul „Istoric” și cardurile „Activitate” și
 * „Zone fierbinți”), în forma trimisă de server (HistoryStatistics, CommitResource, ProcessingRunResource).
 * Datele sunt în format ISO 8601.
 */

/** Un commit importat din istoricul git. */
export type CommitItem = {
    id: number;
    sha: string;
    /** Commit de îmbinare (două sau mai multe commit-uri părinte): git nu arată schimbări pentru el. */
    is_merge: boolean;
    author_name: string;
    authored_at: string;
    /** Prima linie a mesajului. */
    subject: string;
    /** `null` la un merge și când liniile nu au putut fi numărate. */
    files_changed: number | null;
    insertions: number | null;
    deletions: number | null;
};

/** O pagină din lista de commit-uri (Inertia::scroll peste o colecție paginată). */
export type CommitPage = {
    data: CommitItem[];
};

/** Cum a lucrat o rulare GitChronicle: prima procesare, doar ce s-a schimbat sau totul din nou. */
export type ProcessingMode = 'initial' | 'incremental' | 'full';

export type ProcessingRunStatus = 'running' | 'succeeded' | 'failed' | 'skipped';

/** O rulare GitChronicle, afișată pe commit-ul pe care l-a procesat (`sha`). */
export type ProcessingRunItem = {
    id: number;
    sha: string | null;
    mode: ProcessingMode;
    trigger: 'add' | 'manual' | 'webhook' | 'schedule';
    status: ProcessingRunStatus;
    /** `null` cât timp rularea nu s-a terminat. */
    duration_ms: number | null;
    documents_regenerated: number;
    input_tokens: number;
    output_tokens: number;
    /** Cât ar fi costat tokenii prin API, în dolari; `null` când nu se știe. */
    cost_usd_equivalent: number | null;
    started_at: string;
};

/** Cifrele principale ale istoricului importat. */
export type HistorySummary = {
    commit_count: number;
    contributor_count: number;
    first_commit_at: string;
    last_commit_at: string;
};

/** Un fișier schimbat des: câte commit-uri l-au atins, câte linii, de câți oameni. */
export type Hotspot = {
    path: string;
    commit_count: number;
    /** `null` când git nu a putut număra liniile (de exemplu un fișier binar). */
    insertions: number | null;
    deletions: number | null;
    last_changed_at: string;
    author_count: number;
    /** Liniile fișierului acum, din analiza codului; `null` când analiza nu îl are (șters sau neanalizat). */
    lines: number | null;
};

export type Contributor = {
    name: string;
    commit_count: number;
    first_commit_at: string;
    last_commit_at: string;
};

/** Activitatea ultimelor 12 luni (luna curentă ultima) și cifrele întregului istoric importat. */
export type Activity = {
    /**
     * `month` e de forma „2026-11”. `commit_count` e `null` pentru o lună necunoscută: mai veche decât
     * commit-urile importate (se importă doar cele mai noi), deci nu știm câte commit-uri a avut.
     */
    months: { month: string; commit_count: number | null }[];
    /** `null` când una dintre luni e necunoscută. */
    commits_last_year: number | null;
    commit_count: number;
    contributor_count: number;
    /** Cel mai vechi commit importat. */
    first_commit_at: string;
    last_commit_at: string;
    /** Adevărat când s-a importat și primul commit al proiectului, deci tot istoricul. */
    is_complete: boolean;
};
