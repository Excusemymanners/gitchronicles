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

/** Ce a pornit o rulare: adăugarea repository-ului, un om, un push pe GitHub sau verificarea automată. */
export type ProcessingTrigger = 'add' | 'manual' | 'webhook' | 'schedule';

/** Un modul atins de o actualizare: are fișiere schimbate sau e vecin (importă / e importat) cu unul schimbat. */
export type AffectedModule = {
    path: string;
    /** Câte fișiere ale modulului s-au schimbat; 0 pentru un vecin. */
    files: number;
    via: 'changed' | 'imports' | 'imported';
};

/** Ce a găsit schimbat o actualizare față de commit-ul procesat înainte (`change_summary` din Laravel). */
export type RunChanges = {
    /** `null` când rularea nu a putut compara cele două commit-uri. */
    added: number | null;
    modified: number | null;
    deleted: number | null;
    modules: AffectedModule[];
    /** Câte module afectate nu au mai încăput în listă. */
    modules_not_listed: number;
    /** Module (foldere cu cod) apărute sau dispărute la acest commit. */
    modules_added: string[];
    modules_removed: string[];
    /** De ce o rulare a procesat totul în loc să fie incrementală; `null` altfel. */
    full_reason: string | null;
    /** Ce a lăsat deoparte o limită a analizei (repository neobișnuit de mare); `null` când nu s-a lăsat nimic. */
    left_out?: { files: number; symbols: number; imports: number } | null;
};

/** Ultima procesare completă de dinaintea unei actualizări incrementale: cu ea se compară economia. */
export type ReferenceRun = {
    sha: string | null;
    mode: ProcessingMode;
    duration_ms: number | null;
    files_analyzed: number;
    documents_regenerated: number;
    context_characters: number;
    input_tokens: number;
    output_tokens: number;
    cost_usd_equivalent: number | null;
};

/** O rulare GitChronicle, afișată pe commit-ul pe care l-a procesat (`sha`). */
export type ProcessingRunItem = {
    id: number;
    sha: string | null;
    /** Commit-ul procesat înainte, de la care a pornit actualizarea; `null` la prima procesare. */
    from_sha: string | null;
    mode: ProcessingMode;
    trigger: ProcessingTrigger;
    status: ProcessingRunStatus;
    /** `null` cât timp rularea nu s-a terminat. */
    duration_ms: number | null;
    /** Fișierele urmărite de git la acest commit. */
    files_total: number;
    /** Fișierele schimbate față de commit-ul procesat înainte. */
    files_changed: number;
    /** Fișierele citite din nou de analizor (celelalte aveau același conținut ca data trecută). */
    files_analyzed: number;
    modules_total: number;
    modules_affected: number;
    /** Paginile de documentație scrise din nou și cele lăsate cum erau. */
    documents_regenerated: number;
    documents_kept: number;
    /** Câte caractere a primit (sau ar fi primit, fără AI) cel care scrie paginile. */
    context_characters: number;
    input_tokens: number;
    output_tokens: number;
    /** Cât ar fi costat tokenii prin API, în dolari; `null` când nu se știe. */
    cost_usd_equivalent: number | null;
    /** `null` la prima procesare și la rulările de dinaintea actualizării incrementale. */
    changes: RunChanges | null;
    /** Doar la o actualizare incrementală reușită care are înainte o procesare completă. */
    reference: ReferenceRun | null;
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

/** Un commit procesat până la capăt de GitChronicle: o oprire a călătoriei în timp (ProcessedCommits în Laravel). */
export type ProcessedCommit = {
    sha: string;
    /** Când s-a terminat prima rulare reușită pe acest commit. */
    processed_at: string | null;
    /** Mesajul și data commit-ului, dacă e printre commit-urile importate; altfel `null`. */
    subject: string | null;
    authored_at: string | null;
    /** Adevărat pentru cel mai nou commit procesat: starea curentă a repository-ului. */
    is_current: boolean;
};

/** Călătoria în timp a unei pagini: opririle posibile și commit-ul ales (`null` = versiunea curentă). */
export type TimeTravel = {
    /** De la cel mai vechi la cel mai nou. */
    commits: ProcessedCommit[];
    selected: string | null;
};

/** Călătoria în timp pe „Documentație”: textul paginii la commit-ul ales. */
export type DocumentTimeTravel = TimeTravel & {
    /** `null` când nu călătorim sau când pagina nu exista încă la commit-ul ales. */
    version: {
        /** Commit-ul din care a fost scrisă versiunea (cel ales sau unul mai vechi). */
        sha: string | null;
        markdown: string;
        written_at: string | null;
        /** Adevărat când pagina nu s-a mai schimbat de atunci. */
        same_as_current: boolean;
    } | null;
};

/** Călătoria în timp pe „Diagrame”: ce e diferit acum față de commit-ul ales. */
export type DiagramTimeTravel = TimeTravel & {
    changes: {
        /** Titlurile diagramelor care nu mai sunt la fel acum. */
        diagrams: string[];
        /** Module care există acum și nu existau atunci, și invers; `null` când lista de atunci nu a fost păstrată. */
        modules_added: string[] | null;
        modules_removed: string[] | null;
        modules_added_count: number | null;
        modules_removed_count: number | null;
    } | null;
};
