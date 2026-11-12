/** Starea raportului de consultanță: în coadă, în lucru, gata sau eșuat (ConsultingStatus în Laravel). */
export type ConsultingStatus = 'pending' | 'running' | 'ready' | 'failed';

/** Gravitatea unei probleme, aceeași scară pentru toate uneltele (FindingSeverity în Laravel). */
export type Severity = 'critical' | 'high' | 'medium' | 'low' | 'info';

/** De ce a eșuat un raport (un cod; pagina îl formulează). */
export type ConsultingError = 'clone_missing' | 'not_installed' | 'no_tool_ran' | 'unexpected';

/** De ce a eșuat o unealtă, cu numărul care îl însoțește (secunde, MB sau codul de ieșire). */
export type ToolError = 'timed_out' | 'output_too_large' | 'unreadable_output' | 'exit_code';

/** Ce a făcut o unealtă (Semgrep, OSV-Scanner, scc) în această analiză. */
export type ConsultingTool = {
    key: 'semgrep' | 'osv-scanner' | 'scc';
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
    details: { files_scanned?: number; errors?: number; packages_scanned?: number; lockfiles?: string[] };
};

/** Problemele după gravitate și după fel (ConsultingScanner::counts în Laravel). */
export type ConsultingCounts = Record<Severity, number> & {
    bugs: number;
    code_vulnerabilities: number;
    dependency_advisories: number;
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
    tools: ConsultingTool[];
    /** Rezultatele; `null` până când raportul e gata. */
    result: ConsultingResult | null;
};
