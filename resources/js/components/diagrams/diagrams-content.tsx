import type { ReactNode } from 'react';
import DiagramLegend from '@/components/diagrams/diagram-legend';
import type { LegendItem } from '@/components/diagrams/diagram-legend';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import TechnologyList from '@/components/diagrams/technology-list';
import type { Diagram, DiagramSet, DiagramSource } from '@/components/diagrams/types';
import { shortSha } from '@/lib/format';

type Props = {
    diagrams: DiagramSet;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** Ce înseamnă culorile fiecărei vederi C4 (aceleași clase ca în ArchitectureDiagramBuilder). */
const LEGENDS: Record<'layers' | 'container' | 'component' | 'context', LegendItem[]> = {
    layers: [
        { label: 'importă cod din', swatch: 'arrow' },
        { label: 'module care nu au încăput', swatch: 'more' },
    ],
    container: [
        { label: 'Persoană', swatch: 'person' },
        { label: 'Container cu logo-ul tehnologiei', swatch: 'container' },
        { label: 'Container fără logo', swatch: 'containerBox' },
        { label: 'Sistem extern', swatch: 'externalBox' },
        { label: 'Granița sistemului', swatch: 'boundary' },
    ],
    component: [
        { label: 'Componentă (modul)', swatch: 'component' },
        { label: 'importă cod din', swatch: 'arrow' },
        { label: 'Granița sistemului', swatch: 'boundary' },
    ],
    context: [
        { label: 'Persoană', swatch: 'person' },
        { label: 'Sistemul', swatch: 'system' },
        { label: 'Sistem extern', swatch: 'externalBox' },
    ],
};

/**
 * Doar elementele de legendă care apar în diagramă: săgeata, plus clasele folosite în cod („class … nume”).
 */
function legendFor(diagram: Diagram, items: LegendItem[]): LegendItem[] {
    return items.filter((item) => item.swatch === 'arrow' || new RegExp(`^\\s*class\\s+\\S+\\s+${item.swatch}$`, 'm').test(diagram.mermaid));
}

/**
 * Tot conținutul tab-ului „Diagrame”, pe secțiuni: „Cum funcționează” (fluxurile scrise de Claude),
 * „Arhitectura pe straturi” și cele trei vederi C4, de la cea mai detaliată la cea mai generală.
 * O secțiune fără date nu apare; fără nicio diagramă rămâne un mesaj scurt.
 */
export default function DiagramsContent({ diagrams }: Props) {
    const { flows, layers, container, component, context } = diagrams;

    const sections = [
        flows.length > 0 && { id: 'cum-functioneaza', label: 'Cum funcționează' },
        layers && { id: 'straturi', label: 'Straturi' },
        container && { id: 'containere', label: 'Containere' },
        component && { id: 'componente', label: 'Componente' },
        context && { id: 'context', label: 'Context' },
    ].filter((section): section is { id: string; label: string } => Boolean(section));

    if (sections.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                <p className="font-medium text-ink">Încă nu există diagrame pentru acest repository.</p>
                <p className="mt-2">
                    Le desenăm din analiza codului, după procesare. Un proiect fără fișiere de cod (de exemplu doar un README) nu are diagrame.
                </p>
            </div>
        );
    }

    const sourceSha = (layers ?? container ?? component ?? context ?? flows[0])?.source_sha ?? null;

    return (
        <div className="flex flex-col gap-12">
            <div className="flex flex-col gap-3">
                <nav aria-label="Diagramele de pe pagină">
                    <ul className="flex flex-wrap gap-2">
                        {sections.map((section) => (
                            <li key={section.id}>
                                <a
                                    href={`#${section.id}`}
                                    className={`inline-block rounded-full border border-line bg-surface px-3 py-1 text-sm font-medium text-ink-muted hover:border-line-strong hover:text-ink ${focusRing}`}
                                >
                                    {section.label}
                                </a>
                            </li>
                        ))}
                    </ul>
                </nav>
                {sourceSha && (
                    <p className="text-sm text-ink-subtle">
                        Desenate din commitul <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(sourceSha)}</code>, din analiza
                        codului și din fișierele proiectului.
                    </p>
                )}
            </div>

            {flows.length > 0 && (
                <DiagramSection id="cum-functioneaza" title="Cum funcționează" description="Fluxurile principale ale aplicației, explicate de Claude doar din codul proiectului; fiecare are legături spre liniile pe care se sprijină.">
                    <div className="flex flex-col gap-6">
                        {flows.map((flow) => (
                            <FlowCard key={flow.key} flow={flow} />
                        ))}
                    </div>
                </DiagramSection>
            )}

            {layers && (
                <DiagramSection id="straturi" title="Arhitectura pe straturi" description={layers.caption}>
                    <MermaidDiagram code={layers.mermaid} title="Arhitectura pe straturi" />
                    <DiagramLegend items={legendFor(layers, LEGENDS.layers)} />
                    {layers.excluded.length > 0 && (
                        <div className="text-sm text-ink-muted">
                            <p className="font-medium text-ink">Lăsate în afara diagramei</p>
                            <dl className="mt-2 grid gap-x-6 gap-y-2 sm:grid-cols-[max-content_1fr]">
                                {layers.excluded.map((group) => (
                                    <div key={group.label} className="contents">
                                        <dt className="text-ink-subtle">{group.label}</dt>
                                        <dd>
                                            <PathList paths={group.paths} />
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    )}
                </DiagramSection>
            )}

            {container && (
                <DiagramSection id="containere" title="Containere" level="C4 · nivelul 2" description={container.caption}>
                    <MermaidDiagram code={container.mermaid} title="Containere (C4, nivelul 2)" />
                    <DiagramLegend items={legendFor(container, LEGENDS.container)} />
                    {container.technologies.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium text-ink">Tehnologii găsite și fișierul în care apar</p>
                            <TechnologyList technologies={container.technologies} />
                        </div>
                    )}
                </DiagramSection>
            )}

            {component && (
                <DiagramSection id="componente" title="Componente" level="C4 · nivelul 3" description={component.caption}>
                    <MermaidDiagram code={component.mermaid} title="Componente (C4, nivelul 3)" />
                    <DiagramLegend items={legendFor(component, LEGENDS.component)} />
                </DiagramSection>
            )}

            {context && (
                <DiagramSection id="context" title="Context" level="C4 · nivelul 1" description={context.caption}>
                    <MermaidDiagram code={context.mermaid} title="Context (C4, nivelul 1)" />
                    <DiagramLegend items={legendFor(context, LEGENDS.context)} />
                </DiagramSection>
            )}
        </div>
    );
}

type SectionProps = {
    id: string;
    title: string;
    /** Nivelul C4, afișat lângă titlu. */
    level?: string;
    description: string | null;
    children: ReactNode;
};

/** O secțiune a paginii: titlul (cu nivelul C4), propoziția care explică diagrama și conținutul. */
function DiagramSection({ id, title, level, description, children }: SectionProps) {
    return (
        <section id={id} aria-labelledby={`${id}-heading`} className="scroll-mt-6">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id={`${id}-heading`} className="text-lg font-semibold tracking-tight text-ink">
                    {title}
                </h2>
                {level && <span className="text-sm font-medium text-ink-subtle">{level}</span>}
            </div>
            {description && <p className="mt-1 max-w-3xl text-sm text-ink-muted">{description}</p>}
            <div className="mt-4 flex flex-col gap-4">{children}</div>
        </section>
    );
}

/**
 * Un flux din „Cum funcționează”: titlul, explicația, liniile de cod pe care se sprijină și diagrama.
 * Diagrama unui flux e înaltă și îngustă, așa că într-un card destul de lat (container query, nu lățimea
 * ecranului: cardul poate sta și într-un panou mai îngust) textul stă în stânga și diagrama în dreapta.
 */
function FlowCard({ flow }: { flow: Diagram }) {
    return (
        <article className="@container rounded-lg border border-line bg-surface p-4 shadow-xs sm:p-6">
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 @2xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                <div className="flex flex-col gap-4">
                    <div>
                        <h3 className="text-base font-semibold text-ink">{flow.title}</h3>
                        {flow.explanation && <p className="mt-2 text-sm leading-6 text-ink-muted">{flow.explanation}</p>}
                    </div>

                    {flow.sources.length > 0 && (
                        <div className="text-sm">
                            <p className="font-medium text-ink">Unde se vede în cod</p>
                            <ul className="mt-2 flex flex-col gap-1.5">
                                {flow.sources.map((source) => (
                                    <li key={`${source.path}:${source.start_line}`} className="min-w-0">
                                        <SourceLink source={source} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <p className="text-xs text-ink-subtle">Text și diagramă scrise de Claude din codul proiectului; verifică liniile din surse.</p>
                </div>

                <MermaidDiagram code={flow.mermaid} title={flow.title} />
            </div>
        </article>
    );
}

/** Câte căi arătăm dintr-un grup de foldere lăsate deoparte; restul stau sub „încă …”. */
const SHOWN_PATHS = 10;

/** Căile unui grup de foldere: primele SHOWN_PATHS, iar restul într-un <details>, ca lista să nu acopere pagina. */
function PathList({ paths }: { paths: string[] }) {
    const chips = (list: string[]) =>
        list.map((path) => (
            <code key={path} className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-ink">
                {path}
            </code>
        ));

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {chips(paths.slice(0, SHOWN_PATHS))}
            {paths.length > SHOWN_PATHS && (
                <details className="group contents">
                    <summary className={`cursor-pointer rounded-sm text-xs font-medium text-accent group-open:hidden hover:text-accent-hover ${focusRing}`}>
                        încă {paths.length - SHOWN_PATHS}
                    </summary>
                    {chips(paths.slice(SHOWN_PATHS))}
                </details>
            )}
        </div>
    );
}

/** Legătura spre liniile unui fișier pe GitHub; fără commit cunoscut, doar calea și liniile. */
function SourceLink({ source }: { source: DiagramSource }) {
    const label = `${source.path} · L${source.start_line}–L${source.end_line}`;

    if (!source.url) {
        return <code className="font-mono text-xs break-all text-ink">{label}</code>;
    }

    return (
        <a
            href={source.url}
            target="_blank"
            rel="noopener noreferrer"
            className={`rounded-sm font-mono text-xs break-all text-accent hover:text-accent-hover hover:underline ${focusRing}`}
        >
            {label} <span aria-hidden="true">{'↗\uFE0E'}</span>
            <span className="sr-only"> (pe GitHub, se deschide într-o filă nouă)</span>
        </a>
    );
}
