/** Felul unei diagrame (App\Enums\DiagramType). */
export type DiagramType = 'overview' | 'context' | 'container' | 'component' | 'layers' | 'flow';

/** Liniile de cod pe care se sprijină un flux, cu legătura spre ele pe GitHub (null fără commit). */
export type DiagramSource = {
    path: string;
    start_line: number;
    end_line: number;
    url: string | null;
};

/** O tehnologie găsită în fișierele proiectului, cu fișierul care o arată. */
export type DetectedTechnology = {
    name: string;
    /** Numele logo-ului din pachetul Iconify „logos”, de exemplu „logos:laravel”; null fără logo. */
    icon: string | null;
    source: string;
};

/** Un grup de foldere lăsate în afara diagramelor (teste, configurare, ...). */
export type ExcludedGroup = {
    label: string;
    paths: string[];
};

/** Nodurile unei diagrame care duc la documentația unui modul: id-ul nodului -> modulul și slug-ul paginii lui. */
export type DiagramModules = Record<string, { path: string; slug: string }>;

/** A module in the findings: how many modules and imports stand behind it. */
export type FindingModule = { module: string; modules: number; imports: number };

/** What was computed from the real imports of the modules a diagram shows (ArchitectureFacts); absent facts are missing. */
export type DiagramFindings = {
    core?: FindingModule[];
    coordinators?: FindingModule[];
    /** A loop is written in the direction of the imports; size is the number of modules in the whole group. */
    cycles?: { modules: string[]; size: number }[];
    upward?: { count: number; imports: number; pairs: { from: string; to: string; imports: number }[] };
    isolated?: string[];
    coverage: {
        shown_modules: number;
        total_modules: number;
        shown_lines: number;
        total_lines: number;
        total_imports: number;
        undrawn_imports: number;
    };
    busiest?: { module: string; commits: number }[];
    /** 'files' when the diagram's boxes are files (a small repository), absent for modules. */
    unit?: 'files';
};

/** One example behind an arrow: the file (and line) that links, what it links to, and their links on GitHub. */
export type EvidenceExample = { from: string; to: string | null; line?: number | null; excerpt?: string | null; url: string | null; target_url: string | null };

/** A place in the code behind a step, linked to GitHub at its line. */
export type StepCode = { path: string; line: number | null; excerpt: string | null; url: string | null };

/** One numbered step of a flow, or one arrow of the big picture (numbered when it is on the main path). */
export type DiagramStep = {
    /** Node or participant ids, as in the Mermaid code. */
    from: string;
    to: string;
    from_label: string;
    to_label: string;
    text: string;
    step: number | null;
    type?: 'request' | 'response';
    code: StepCode[];
};

/** A part of the big picture: what it is, what it does and the modules (or the systems outside the code) it stands for. */
export type DiagramPart = {
    id: string;
    label: string;
    does: string | null;
    kind: 'interface' | 'service' | 'worker' | 'data' | 'external' | 'tool';
    modules: { path: string; slug: string | null }[];
    outside: string[];
};

/** One arrow of the diagram with the imports that stand behind it. */
export type DiagramEvidence = {
    from: string;
    to: string;
    from_label: string;
    to_label: string;
    imports: number;
    examples: EvidenceExample[];
    /** The drawn boxes that take part in the arrow: it is highlighted when one of them is focused. */
    nodes: string[];
};

/** The reading of an architecture diagram: written by Claude, or composed by the page from the findings (no text). */
export type DiagramInterpretation = {
    source: 'ai' | 'composed';
    reading?: string;
    worth?: string;
    model?: string;
    reason?: string;
};

/** O diagramă, așa cum o trimite DiagramResource. */
export type Diagram = {
    type: DiagramType;
    /** Deosebește fluxurile între ele; '' pentru celelalte diagrame. */
    key: string;
    title: string;
    /** O propoziție despre ce arată diagrama (diagramele de arhitectură). */
    caption: string | null;
    /** Explicația fluxului, scrisă de Claude (doar fluxurile). */
    explanation: string | null;
    mermaid: string;
    excluded: ExcludedGroup[];
    technologies: DetectedTechnology[];
    sources: DiagramSource[];
    /** Commitul din care a fost desenată. */
    source_sha: string | null;
    /** Nodurile care deschid pagina unui modul (doar straturile și componentele). */
    modules: DiagramModules;
    /** Null for a diagram drawn before the findings existed: the page then shows nothing extra. */
    findings: DiagramFindings | null;
    evidence: DiagramEvidence[];
    interpretation: DiagramInterpretation | null;
    /** The big picture's two sentences on how to read it. */
    reading: string | null;
    /** Where the big picture or a flow came from: 'ai' (checked against the code) or 'fallback' (without AI). */
    source: string | null;
    /** How many proposed arrows or messages were dropped because nothing in the code backs them. */
    dropped: number;
    /** The main flow (the end-to-end path of what the project does). */
    main: boolean;
    /** The shapes the big picture uses, for its legend. */
    legend: string[];
    /** What was left out because nothing connects it. */
    unconnected: string[];
    parts: DiagramPart[];
    steps: DiagramStep[];
};

/** Toate diagramele unui repository, grupate pentru pagină; null = vederea nu are date. */
export type DiagramSet = {
    overview: Diagram | null;
    flows: Diagram[];
    layers: Diagram | null;
    container: Diagram | null;
    component: Diagram | null;
    context: Diagram | null;
};
