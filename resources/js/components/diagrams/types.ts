/** Felul unei diagrame (App\Enums\DiagramType). */
export type DiagramType = 'context' | 'container' | 'component' | 'layers' | 'flow';

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
};

/** Toate diagramele unui repository, grupate pentru pagină; null = vederea nu are date. */
export type DiagramSet = {
    flows: Diagram[];
    layers: Diagram | null;
    container: Diagram | null;
    component: Diagram | null;
    context: Diagram | null;
};
