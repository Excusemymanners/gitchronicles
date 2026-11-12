import { useEffect, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import DiagramLegend from '@/components/diagrams/diagram-legend';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import TechnologyList from '@/components/diagrams/technology-list';
import type { Diagram, DiagramSet, DiagramSource } from '@/components/diagrams/types';
import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { ExternalLinkIcon } from '@/components/icons';
import { shortSha } from '@/lib/format';
import { translate } from '@/lib/i18n';

type Props = {
    diagrams: DiagramSet;
    /** Id of the repository: the module pages the diagram boxes open are under /repositories/{id}/docs. */
    repositoryId: number;
};

/** The reading line of the sub-navigation: this far below the top of the scroll area. */
const READING_LINE = 120;

/**
 * The section being read: the last one whose heading is above a line 120 px below the top of the scroll area.
 * Positions, not an IntersectionObserver band (same approach as the docs page); recomputed once per frame.
 */
function useActiveSection(ids: string[], rootRef: RefObject<HTMLElement | null>): string | null {
    const [activeId, setActiveId] = useState<string | null>(ids[0] ?? null);
    const key = ids.join('|');

    useEffect(() => {
        const root = rootRef.current;

        if (!root || ids.length === 0) {
            return;
        }

        const scrollArea = root.closest<HTMLElement>('[scroll-region]');
        const target: HTMLElement | Window = scrollArea ?? window;
        let frame = 0;

        const update = () => {
            frame = 0;
            const line = (scrollArea?.getBoundingClientRect().top ?? 0) + READING_LINE;
            let current = ids[0];

            ids.forEach((id) => {
                const element = document.getElementById(id);

                if (element && element.getBoundingClientRect().top <= line) {
                    current = id;
                }
            });

            setActiveId(current);
        };

        const onScroll = () => {
            if (frame === 0) {
                frame = window.requestAnimationFrame(update);
            }
        };

        update();
        target.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            target.removeEventListener('scroll', onScroll);
            window.cancelAnimationFrame(frame);
        };
    }, [key]); // eslint-disable-line react-hooks/exhaustive-deps

    return activeId;
}

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** Ce înseamnă culorile fiecărei vederi C4 (aceleași clase ca în ArchitectureDiagramBuilder). */
type LegendSpec = { labelKey: TranslationKey; swatch: string };

const LEGENDS: Record<'layers' | 'container' | 'component' | 'context', LegendSpec[]> = {
    layers: [
        { labelKey: 'diagrams.legend.importsFrom', swatch: 'arrow' },
        { labelKey: 'diagrams.legend.modulesLeftOut', swatch: 'more' },
    ],
    container: [
        { labelKey: 'diagrams.legend.person', swatch: 'person' },
        { labelKey: 'diagrams.legend.containerWithLogo', swatch: 'container' },
        { labelKey: 'diagrams.legend.containerWithoutLogo', swatch: 'containerBox' },
        { labelKey: 'diagrams.legend.externalSystem', swatch: 'externalBox' },
        { labelKey: 'diagrams.legend.boundary', swatch: 'boundary' },
    ],
    component: [
        { labelKey: 'diagrams.legend.component', swatch: 'component' },
        { labelKey: 'diagrams.legend.importsFrom', swatch: 'arrow' },
        { labelKey: 'diagrams.legend.boundary', swatch: 'boundary' },
    ],
    context: [
        { labelKey: 'diagrams.legend.person', swatch: 'person' },
        { labelKey: 'diagrams.legend.system', swatch: 'system' },
        { labelKey: 'diagrams.legend.externalSystem', swatch: 'externalBox' },
    ],
};

/**
 * Doar elementele de legendă care apar în diagramă: săgeata, plus clasele folosite în cod („class … nume”).
 */
function legendFor(diagram: Diagram, items: LegendSpec[]): { label: string; swatch: string }[] {
    return items
        .filter((item) => item.swatch === 'arrow' || new RegExp(`^\\s*class\\s+\\S+\\s+${item.swatch}$`, 'm').test(diagram.mermaid))
        .map((item) => ({ label: translate(item.labelKey), swatch: item.swatch }));
}

/**
 * Tot conținutul tab-ului „Diagrame”, pe secțiuni: „Cum funcționează” (fluxurile scrise de Claude),
 * „Arhitectura pe straturi” și cele trei vederi C4, de la cea mai detaliată la cea mai generală.
 * O secțiune fără date nu apare; fără nicio diagramă rămâne un mesaj scurt.
 */
export default function DiagramsContent({ diagrams, repositoryId }: Props) {
    const { t, rich } = useI18n();
    const rootRef = useRef<HTMLDivElement>(null);
    const docsBase = `/repositories/${repositoryId}/docs`;
    const { flows, layers, container, component, context } = diagrams;

    const sections = [
        flows.length > 0 && { id: 'cum-functioneaza', label: t('diagrams.content.sections.flow') },
        layers && { id: 'straturi', label: t('diagrams.content.sections.layers') },
        container && { id: 'containere', label: t('diagrams.content.sections.container') },
        component && { id: 'componente', label: t('diagrams.content.sections.component') },
        context && { id: 'context', label: t('diagrams.content.sections.context') },
    ].filter((section): section is { id: string; label: string } => Boolean(section));

    const activeSection = useActiveSection(
        sections.map((section) => section.id),
        rootRef,
    );

    if (sections.length === 0) {
        return (
            <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                <p className="font-medium text-ink">{t('diagrams.content.emptyTitle')}</p>
                <p className="mt-2">{t('diagrams.content.emptyText')}</p>
            </div>
        );
    }

    const sourceSha = (layers ?? container ?? component ?? context ?? flows[0])?.source_sha ?? null;

    return (
        <div ref={rootRef} className="flex flex-col gap-10">
            {sourceSha && (
                <p className="text-sm text-ink-subtle">
                    {rich('diagrams.content.drawnFrom', {
                        sha: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(sourceSha)}</code>,
                    })}
                </p>
            )}

            <nav aria-label={t('diagrams.content.nav')} className="sticky top-0 z-10 -mt-4 border-b border-line bg-canvas/95 backdrop-blur-sm">
                <ul className="flex gap-1 overflow-x-auto">
                    {sections.map((section) => (
                        <li key={section.id}>
                            <a
                                href={`#${section.id}`}
                                aria-current={activeSection === section.id ? 'location' : undefined}
                                className={`relative inline-block px-3 py-2.5 text-sm font-medium whitespace-nowrap hover:text-ink ${focusRing} ${
                                    activeSection === section.id
                                        ? 'text-ink after:absolute after:inset-x-3 after:bottom-0 after:h-0.5 after:bg-accent'
                                        : 'text-ink-muted'
                                }`}
                            >
                                {section.label}
                            </a>
                        </li>
                    ))}
                </ul>
            </nav>

            {flows.length > 0 && (
                <DiagramSection id="cum-functioneaza" title={t('diagrams.content.sections.flow')} description={t('diagrams.content.flowDescription')}>
                    <div className="flex flex-col gap-6">
                        {flows.map((flow) => (
                            <FlowCard key={flow.key} flow={flow} docsBase={docsBase} />
                        ))}
                    </div>
                </DiagramSection>
            )}

            {layers && (
                <DiagramSection id="straturi" title={t('diagrams.content.layersTitle')} description={layers.caption}>
                    <MermaidDiagram code={layers.mermaid} title={t('diagrams.content.layersTitle')} modules={layers.modules} docsBase={docsBase} />
                    <DiagramLegend items={legendFor(layers, LEGENDS.layers)} />
                    {layers.excluded.length > 0 && (
                        <div className="text-sm text-ink-muted">
                            <p className="font-medium text-ink">{t('diagrams.content.excluded')}</p>
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
                <DiagramSection
                    id="containere"
                    title={t('diagrams.content.sections.container')}
                    level={t('diagrams.content.levels.container')}
                    description={container.caption}
                >
                    <MermaidDiagram code={container.mermaid} title={t('diagrams.content.titles.container')} />
                    <DiagramLegend items={legendFor(container, LEGENDS.container)} />
                    {container.technologies.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm font-medium text-ink">{t('diagrams.content.technologies')}</p>
                            <TechnologyList technologies={container.technologies} />
                        </div>
                    )}
                </DiagramSection>
            )}

            {component && (
                <DiagramSection
                    id="componente"
                    title={t('diagrams.content.sections.component')}
                    level={t('diagrams.content.levels.component')}
                    description={component.caption}
                >
                    <MermaidDiagram code={component.mermaid} title={t('diagrams.content.titles.component')} modules={component.modules} docsBase={docsBase} />
                    <DiagramLegend items={legendFor(component, LEGENDS.component)} />
                </DiagramSection>
            )}

            {context && (
                <DiagramSection
                    id="context"
                    title={t('diagrams.content.sections.context')}
                    level={t('diagrams.content.levels.context')}
                    description={context.caption}
                >
                    <MermaidDiagram code={context.mermaid} title={t('diagrams.content.titles.context')} />
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
        <section id={id} aria-labelledby={`${id}-heading`} className="scroll-mt-16">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 id={`${id}-heading`} className="font-display text-heading text-ink">
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
function FlowCard({ flow, docsBase }: { flow: Diagram; docsBase: string }) {
    const { t } = useI18n();

    return (
        <article className="rounded-lg border border-line bg-surface p-4 shadow-card sm:p-6">
            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]">
                <div className="flex flex-col gap-4">
                    <div>
                        <h3 className="font-display text-lg font-semibold text-ink">{flow.title}</h3>
                        {flow.explanation && <p className="mt-2 max-w-[60ch] text-sm leading-6 text-ink-muted">{flow.explanation}</p>}
                    </div>

                    {flow.sources.length > 0 && (
                        <div className="text-sm">
                            <p className="font-medium text-ink">{t('diagrams.content.whereInCode')}</p>
                            <ul className="mt-2 flex flex-wrap gap-2">
                                {flow.sources.map((source) => (
                                    <li key={`${source.path}:${source.start_line}`} className="min-w-0">
                                        <SourceLink source={source} />
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <p className="text-xs text-ink-subtle">{t('diagrams.content.flowNote')}</p>
                </div>

                <MermaidDiagram code={flow.mermaid} title={flow.title} modules={flow.modules} docsBase={docsBase} />
            </div>
        </article>
    );
}

/** Câte căi arătăm dintr-un grup de foldere lăsate deoparte; restul stau sub „încă …”. */
const SHOWN_PATHS = 10;

/** Căile unui grup de foldere: primele SHOWN_PATHS, iar restul într-un <details>, ca lista să nu acopere pagina. */
function PathList({ paths }: { paths: string[] }) {
    const { t } = useI18n();

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
                        {t('diagrams.content.moreItems', { count: paths.length - SHOWN_PATHS })}
                    </summary>
                    {chips(paths.slice(SHOWN_PATHS))}
                </details>
            )}
        </div>
    );
}

/** A citation chip, like the assistant's: path in monospace, the lines, an external icon; without a commit, no link. */
function SourceLink({ source }: { source: DiagramSource }) {
    const { t } = useI18n();
    const chip = 'inline-flex max-w-full items-center gap-1.5 rounded-md bg-surface-2 px-2 py-1 font-mono text-xs text-ink ring-1 ring-line ring-inset';
    const content = (
        <>
            <span className="min-w-0 break-all">{source.path}</span>
            <span className="shrink-0 text-ink-subtle">
                L{source.start_line}–L{source.end_line}
            </span>
        </>
    );

    if (!source.url) {
        return <span className={chip}>{content}</span>;
    }

    return (
        <a href={source.url} target="_blank" rel="noopener noreferrer" className={`${chip} hover:bg-accent-soft hover:text-accent ${focusRing}`}>
            {content}
            <ExternalLinkIcon className="size-3.5 text-ink-subtle" />
            <span className="sr-only"> {t('diagrams.content.onGitHub')}</span>
        </a>
    );
}
