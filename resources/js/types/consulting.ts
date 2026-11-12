/** Starea raportului de consultanță: în coadă, în lucru, gata sau eșuat (ConsultingStatus în Laravel). */
export type ConsultingStatus = 'pending' | 'running' | 'ready' | 'failed';

/** Gravitatea unei probleme, aceeași scară pentru toate uneltele (FindingSeverity în Laravel). */
export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';

/** De ce a eșuat un raport (un cod; pagina îl formulează). */
export type ConsultingError = 'clone_missing' | 'not_installed' | 'no_tool_ran' | 'unexpected';

/** De ce a eșuat o unealtă, cu numărul care îl însoțește (secunde, MB sau codul de ieșire). */
export type ToolError = 'timed_out' | 'output_too_large' | 'unreadable_output' | 'exit_code' | 'source_unavailable';

/** Ce a făcut o unealtă (Semgrep, OSV-Scanner, scc) în această analiză. */
export type ConsultingTool = {
    key: 'semgrep' | 'osv-scanner' | 'scc' | 'runtime';
    name: string;
    status: 'ran' | 'not_installed' | 'failed' | 'timed_out';
    status_label: string;
    version: string | null;
    duration_ms: number | null;
    finding_count: number;
    /** Câte probleme au rămas pe dinafară din cauza limitei pe unealtă (cele mai puțin grave). */
    omitted_count: number;
    error: ToolError | null;
    error_value: number | null;
    /** Semgrep: files_scanned, errors; OSV-Scanner: packages_scanned, lockfiles; scc: languages, totals. */
    details: { files_scanned?: number; errors?: number; packages_scanned?: number; lockfiles?: string[]; versions_declared?: number };
};

/** Starea unei versiuni declarate față de datele publice de suport (RuntimeVersionCheck în Laravel). */
export type RuntimeStatus = 'end_of_life' | 'behind' | 'security_only' | 'current' | 'unknown' | 'unchecked';

/** Un rând din tabelul „Versiuni de runtime și framework”. Datele sunt zile (`2026-11-12`), așa cum le dau sursele. */
export type RuntimeRow = {
    product: string;
    label: string;
    kind: 'runtime' | 'framework';
    /** Textul din fișier („^8.2”, „node:20-alpine”). */
    declared: string;
    /** Versiunea judecată: cea fixată sau cea mai mică pe care o permite intervalul. */
    version: string;
    is_range: boolean;
    path: string;
    line: number | null;
    status: RuntimeStatus;
    /** Linia de versiuni căreia îi aparține („8.3”). */
    release_line: string | null;
    supported_until: string | null;
    /** Cea mai nouă versiune din linie. */
    newest: string | null;
    /** Linia la care se trece (starea „fără suport” sau „doar securitate”). */
    target: string | null;
    target_newest: string | null;
    missing_security_releases: number | null;
    /** De ce n-a putut fi verificată (starea „neverificată”). */
    reason: 'unreachable' | 'http_error' | 'unreadable' | null;
    url: string | null;
};

/** Problemele după gravitate și după fel (ConsultingScanner::counts în Laravel). */
export type ConsultingCounts = Record<Severity, number> & {
    bugs: number;
    code_vulnerabilities: number;
    dependency_advisories: number;
    runtime_issues?: number;
    vulnerable_packages: number;
};

/** Gravitatea unei probleme: valoarea normalizată, eticheta ei și ce a spus unealta („ERROR”, „CVSS 7.5”). */
type SeverityFields = {
    severity: Severity;
    severity_label: string;
    original_severity: string | null;
};

/** O problemă găsită de Semgrep în cod, cu rândurile ei pe GitHub, la commitul analizat. */
export type CodeFinding = SeverityFields & {
    id: number;
    kind: 'bug' | 'vulnerability';
    kind_label: string;
    rule_id: string;
    title: string;
    message: string;
    start_line: number | null;
    end_line: number | null;
    url: string;
};

/** Un fișier cu problemele lui, cel mai grav primul. */
export type CodeFile = {
    path: string;
    url: string;
    worst_severity: Severity;
    findings: CodeFinding[];
};

/** O dependență cu o vulnerabilitate cunoscută (OSV-Scanner). */
export type DependencyFinding = SeverityFields & {
    id: number;
    package: string;
    ecosystem: string | null;
    installed_version: string | null;
    /** Versiunea care repară vulnerabilitatea; `null` când avertismentul nu spune una. */
    fixed_version: string | null;
    title: string;
    advisory_id: string | null;
    advisory_url: string | null;
    /** Fișierul lock în care e pachetul. */
    path: string | null;
};

/** O zonă de risc: un fișier schimbat des și cu probleme, cu scorul lui (RiskRanking în Laravel). */
export type RiskZone = {
    path: string;
    url: string;
    commit_count: number;
    finding_count: number;
    severity_points: number;
    score: number;
    worst_severity: Severity;
    worst_severity_label: string;
};

/** Un limbaj numărat de scc. */
export type LanguageStatistics = {
    name: string;
    files: number;
    lines: number;
    code: number;
    comments: number;
    blanks: number;
    complexity: number;
};

/** Rezultatele unui raport gata. */
export type ConsultingResult = {
    /** Rezumatul scris de AI, în engleză, doar din cifrele raportului; `null` fără AI. */
    summary: string | null;
    counts: ConsultingCounts;
    finding_count: number;
    omitted_count: number;
    /** Versiunile declarate și starea lor; `null` când verificarea nu face parte din raport (rapoarte mai vechi). */
    runtime: { rows: RuntimeRow[]; error: ToolError | null; error_value: number | null; fetched_at: string | null } | null;
    risk: {
        /** Cât cântărește fiecare gravitate în scor. */
        weights: Record<Severity, number>;
        /** Dintre câte fișiere schimbate cel mai des se aleg zonele. */
        candidate_count: number;
        zones: RiskZone[];
    };
    files: CodeFile[];
    dependencies: DependencyFinding[];
    code_statistics: { languages: LanguageStatistics[]; totals: Omit<LanguageStatistics, 'name'> } | null;
    download_url: string;
};

/** Cel mai nou raport de consultanță al repository-ului (ConsultingReportResource în Laravel). */
export type ConsultingReport = {
    status: ConsultingStatus;
    status_label: string;
    /** Adevărat cât timp raportul e în coadă sau în lucru: pagina se actualizează singură. */
    is_working: boolean;
    error: ConsultingError | null;
    /** Commitul analizat. */
    sha: string | null;
    /** Adevărat când repository-ul a ajuns la un commit mai nou decât cel analizat. */
    is_stale: boolean;
    started_at: string | null;
    finished_at: string | null;
    /** Zilele întregi de la terminarea raportului; `null` până e gata. */
    age_days: number | null;
    /** De la câte zile un raport e socotit vechi (7). */
    stale_after_days: number;
    /** De când sunt datele fiecărei surse (regulile Semgrep, interogarea OSV, datele de suport). */
    freshness: {
        has_rules: boolean;
        rules: { updated_at: string; revision: string; sources: number } | null;
        has_osv: boolean;
        osv_queried_at: string | null;
        has_runtime: boolean;
        runtime_fetched_at: string | null;
    };
    tools: ConsultingTool[];
    /** Rezultatele; `null` până când raportul e gata. */
    result: ConsultingResult | null;
};
