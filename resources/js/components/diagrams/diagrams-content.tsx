import { Link } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import DiagramLegend from '@/components/diagrams/diagram-legend';
import DiagramMeaning from '@/components/diagrams/diagram-meaning';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import TechnologyList from '@/components/diagrams/technology-list';
import type { Diagram, DiagramPart, DiagramSet, DiagramStep, StepCode } from '@/components/diagrams/types';
import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { ExternalLinkIcon } from '@/components/icons';
import { formatNumber, shortSha } from '@/lib/format';
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

/** The legend of the big picture: one line per shape it really uses. */
const MAP_LEGEND: Record<string, { labelKey: TranslationKey; swatch: string }> = {
    service: { labelKey: 'diagrams.explain.legend.mapPart', swatch: 'mapPart' },
    interface: { labelKey: 'diagrams.explain.legend.mapInterface', swatch: 'mapInterface' },
    worker: { labelKey: 'diagrams.explain.legend.mapWorker', swatch: 'mapWorker' },
    data: { labelKey: 'diagrams.explain.legend.mapData', swatch: 'mapData' },
    external: { labelKey: 'diagrams.explain.legend.mapExternal', swatch: 'mapExternal' },
    tool: { labelKey: 'diagrams.explain.legend.mapTool', swatch: 'mapTool' },
    main: { labelKey: 'diagrams.explain.legend.main', swatch: 'main' },
};

/**
 * The whole Diagrams tab, ordered by the question each diagram answers: "How does it work?" (the big picture),
 * "What happens when someone uses it?" (the flows as sequences, the main one first), "What does it talk to?"
 * (context, then containers) and, collapsed, "Which modules depend on which?" (the dependency maps).
 * A section without data does not appear; without any diagram a short message remains.
 */
export default function DiagramsContent({ diagrams, repositoryId }: Props) {
    const { t, rich } = useI18n();
    const rootRef = useRef<HTMLDivElement>(null);
    const docsBase = `/repositories/${repositoryId}/docs`;
    const { overview, flows, layers, container, component, context } = diagrams;

    const sections = [
        overview && { id: 'cum-functioneaza', label: t('diagrams.explain.nav.how') },
        flows.length > 0 && { id: 'ce-se-intampla', label: t('diagrams.explain.nav.what') },
        (context || container) && { id: 'cu-ce-comunica', label: t('diagrams.explain.nav.talks') },
        (component || layers) && { id: 'dependente', label: t('diagrams.explain.nav.depends') },
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

    const sourceSha = (overview ?? layers ?? container ?? component ?? context ?? flows[0])?.source_sha ?? null;

    return (
        <div ref={rootRef} className="flex flex-col gap-12">
            {sourceSha && (
                <p className="text-sm text-ink-subtle">
                    {rich('diagrams.content.drawnFrom', {
                        sha: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(sourceSha)}</code>,
                    })}
                </p>
            )}

            <nav aria-label={t('diagrams.content.nav')} className="sticky top-0 z-10 -mt-6 border-b border-line bg-canvas/95 backdrop-blur-sm">
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

            {overview && (
                <DiagramSection id="cum-functioneaza" title={t('diagrams.explain.questions.how')} description={t('diagrams.explain.hints.how')}>
                    <SystemMapView diagram={overview} docsBase={docsBase} />
                </DiagramSection>
            )}

            {flows.length > 0 && (
                <DiagramSection id="ce-se-intampla" title={t('diagrams.explain.questions.what')} description={t('diagrams.explain.hints.what')}>
                    <div className="flex flex-col gap-6">
                        {flows.map((flow) => (
                            <FlowCard key={flow.key} flow={flow} />
                        ))}
                    </div>
                </DiagramSection>
            )}

            {(context || container) && (
                <DiagramSection id="cu-ce-comunica" title={t('diagrams.explain.questions.talks')} description={t('diagrams.explain.hints.talks')}>
                    {context && (
                        <div className="flex flex-col gap-3">
                            <h3 className="font-display text-lg font-semibold text-ink">{t('diagrams.content.titles.context')}</h3>
                            {context.caption && <p className="max-w-3xl text-sm text-ink-muted">{context.caption}</p>}
                            <ArchitectureView diagram={context} title={t('diagrams.content.titles.context')} docsBase={docsBase} legend={legendFor(context, LEGENDS.context)} />
                        </div>
                    )}
                    {container && (
                        <div className="flex flex-col gap-3">
                            <h3 className="font-display text-lg font-semibold text-ink">{t('diagrams.content.titles.container')}</h3>
                            {container.caption && <p className="max-w-3xl text-sm text-ink-muted">{container.caption}</p>}
                            <ArchitectureView diagram={container} title={t('diagrams.content.titles.container')} docsBase={docsBase} legend={legendFor(container, LEGENDS.container)} />
                            <Unconnected items={container.unconnected} />
                            {container.technologies.length > 0 && (
                                <div className="flex flex-col gap-2">
                                    <p className="text-sm font-medium text-ink">{t('diagrams.content.technologies')}</p>
                                    <TechnologyList technologies={container.technologies} />
                                </div>
                            )}
                        </div>
                    )}
                </DiagramSection>
            )}

            {(component || layers) && (
                <DiagramSection id="dependente" title={t('diagrams.explain.questions.depends')} description={t('diagrams.explain.hints.depends')}>
                    <details className="group rounded-lg border border-line bg-surface">
                        <summary className={`flex cursor-pointer items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-ink select-none hover:bg-surface-2 ${focusRing}`}>
                            <span aria-hidden="true" className="text-ink-subtle transition-transform group-open:rotate-90">
                                ▸
                            </span>
                            {t('diagrams.explain.showMaps')}
                        </summary>
                        <div className="flex flex-col gap-8 border-t border-line p-4">
                            {component && (
                                <div className="flex flex-col gap-3">
                                    <h3 className="font-display text-lg font-semibold text-ink">{component.title}</h3>
                                    {component.caption && <p className="max-w-3xl text-sm text-ink-muted">{component.caption}</p>}
                                    <ArchitectureView diagram={component} title={component.title} docsBase={docsBase} legend={legendFor(component, LEGENDS.component)} />
                                    <Unconnected items={component.unconnected} />
                                </div>
                            )}
                            {layers && (
                                <div className="flex flex-col gap-3">
                                    <h3 className="font-display text-lg font-semibold text-ink">{layers.title}</h3>
                                    {layers.caption && <p className="max-w-3xl text-sm text-ink-muted">{layers.caption}</p>}
                                    <ArchitectureView diagram={layers} title={layers.title} docsBase={docsBase} legend={legendFor(layers, LEGENDS.layers)} />
                                    <Unconnected items={layers.unconnected} />
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
                                </div>
                            )}
                        </div>
                    </details>
                </DiagramSection>
            )}
        </div>
    );
}

/** "Also in the repository, not connected by anything we could detect: …" (nothing when everything is connected). */
function Unconnected({ items }: { items: string[] }) {
    const { t } = useI18n();

    if (items.length === 0) {
        return null;
    }

    const shown = items.slice(0, 12).join(', ') + (items.length > 12 ? ` ${t('diagrams.content.moreItems', { count: items.length - 12 })}` : '');

    return <p className="max-w-3xl text-sm text-ink-subtle">{t('diagrams.explain.unconnected', { items: shown })}</p>;
}

/**
 * The big picture with, beside it, the numbered main path and the other connections (each with the code behind it)
 * and, under it, the parts and what they stand for. Hovering or focusing a step lights its arrow; hovering a box
 * lights the steps that touch it.
 */
function SystemMapView({ diagram, docsBase }: { diagram: Diagram; docsBase: string }) {
    const { t } = useI18n();
    const [activeNode, setActiveNode] = useState<string | null>(null);
    const [activeStep, setActiveStep] = useState<number | null>(null);
    const step = activeStep === null ? null : diagram.steps[activeStep];
    const highlight = useMemo(
        () => (step ? { edges: [[step.from, step.to] as [string, string]], nodes: [step.from, step.to] } : activeNode ? { nodes: [activeNode] } : undefined),
        [step, activeNode],
    );
    const legend = diagram.legend
        .filter((kind) => kind in MAP_LEGEND)
        .map((kind) => ({ label: translate(MAP_LEGEND[kind].labelKey), swatch: MAP_LEGEND[kind].swatch }));
    const mainSteps = diagram.steps.filter((item) => item.step !== null);
    const otherSteps = diagram.steps.filter((item) => item.step === null);

    return (
        <>
            {diagram.reading && <p className="max-w-3xl text-[0.9375rem] leading-7 text-ink">{diagram.reading}</p>}
            <div className="flex min-w-0 flex-col gap-4">
                <div className="flex min-w-0 flex-col gap-2">
                    <MermaidDiagram code={diagram.mermaid} title={diagram.title} modules={diagram.modules} docsBase={docsBase} onNodeActive={setActiveNode} highlight={highlight} fitWidth />
                    <DiagramLegend items={legend} />
                </div>
                <div className="flex min-w-0 flex-col gap-4">
                    {mainSteps.length > 0 && (
                        <StepList title={t('diagrams.explain.mainPath')} steps={diagram.steps} shown={mainSteps} activeNode={activeNode} activeStep={activeStep} onActive={setActiveStep} columns />
                    )}
                    {otherSteps.length > 0 && (
                        <StepList title={t('diagrams.explain.otherArrows')} steps={diagram.steps} shown={otherSteps} activeNode={activeNode} activeStep={activeStep} onActive={setActiveStep} columns />
                    )}
                    <p className="text-xs text-ink-subtle">
                        {diagram.source === 'ai' ? t('diagrams.explain.sourceAi') : t('diagrams.explain.sourceFallback')}
                        {diagram.source === 'ai' && diagram.dropped > 0 && <> {t('diagrams.explain.dropped', { count: formatNumber(diagram.dropped) })}</>}
                    </p>
                </div>
            </div>
            <PartList parts={diagram.parts} docsBase={docsBase} activeNode={activeNode} onActive={setActiveNode} />
            <DiagramMeaning diagram={diagram} docsBase={docsBase} activeNode={activeNode} />
        </>
    );
}

type StepListProps = {
    title: string;
    /** Every step, so an item keeps its index in the diagram's list. */
    steps: DiagramStep[];
    shown: DiagramStep[];
    activeNode: string | null;
    activeStep: number | null;
    onActive: (index: number | null) => void;
    /** Two columns on a wide screen (a list under the diagram rather than beside it). */
    columns?: boolean;
};

/** A list of steps beside a diagram: "1. Web app → Background jobs: starts processing", with the code behind it. */
function StepList({ title, steps, shown, activeNode, activeStep, onActive, columns = false }: StepListProps) {
    const { t } = useI18n();

    return (
        <div>
            <h3 className="text-small font-medium text-ink-subtle">{title}</h3>
            <ol className={`mt-2 grid gap-1 ${columns ? 'lg:grid-flow-col lg:grid-cols-2' : ''}`} style={columns ? { gridTemplateRows: `repeat(${Math.ceil(shown.length / 2)}, auto)` } : undefined}>
                {shown.map((item) => {
                    const index = steps.indexOf(item);
                    const isLit = activeStep === index || (activeNode !== null && (item.from === activeNode || item.to === activeNode));

                    return (
                        <li
                            key={`${item.from}>${item.to}>${index}`}
                            tabIndex={0}
                            onPointerEnter={() => onActive(index)}
                            onPointerLeave={() => onActive(null)}
                            onFocus={() => onActive(index)}
                            onBlur={() => onActive(null)}
                            data-highlighted={isLit ? 'true' : undefined}
                            className={`flex gap-2.5 rounded-md px-2 py-1.5 text-sm data-[highlighted=true]:bg-accent-soft ${focusRing}`}
                        >
                            {item.step !== null ? (
                                <span className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-accent text-[0.6875rem] font-semibold text-on-accent tabular-nums">{item.step}</span>
                            ) : (
                                <span aria-hidden="true" className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center text-ink-subtle">
                                    →
                                </span>
                            )}
                            <span className="min-w-0">
                                <span className="text-ink">
                                    <span className="font-medium">{item.from_label}</span> → <span className="font-medium">{item.to_label}</span>: {item.text}
                                    {item.type === 'response' && <span className="text-ink-subtle"> ({t('diagrams.explain.answer')})</span>}
                                </span>
                                {item.code.map((code) => (
                                    <span key={`${code.path}:${code.line}`} className="mt-0.5 block text-xs text-ink-subtle">
                                        <CodeLink code={code} />
                                        {code.excerpt && <span className="block break-words">{code.excerpt}</span>}
                                    </span>
                                ))}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

/** A file and line of the code, linked to GitHub when the commit is known. */
function CodeLink({ code }: { code: StepCode }) {
    const { t } = useI18n();
    const text = (
        <span className="font-mono break-all">
            {code.path}
            {code.line !== null && `:${code.line}`}
        </span>
    );

    if (!code.url) {
        return text;
    }

    return (
        <a href={code.url} target="_blank" rel="noopener noreferrer" className={`inline-flex items-baseline gap-1 rounded-sm text-ink-muted hover:text-accent ${focusRing}`}>
            {text}
            <ExternalLinkIcon className="size-3 shrink-0 self-center" />
            <span className="sr-only"> {t('diagrams.content.onGitHub')}</span>
        </a>
    );
}

/** The parts of the big picture: what each one is, what it does, and its modules (linked to their pages). */
function PartList({ parts, docsBase, activeNode, onActive }: { parts: DiagramPart[]; docsBase: string; activeNode: string | null; onActive: (id: string | null) => void }) {
    const { t } = useI18n();

    if (parts.length === 0) {
        return null;
    }

    return (
        <div>
            <h3 className="text-small font-medium text-ink-subtle">{t('diagrams.explain.parts')}</h3>
            <ul className="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {parts.map((part) => (
                    <li
                        key={part.id}
                        tabIndex={0}
                        onPointerEnter={() => onActive(part.id)}
                        onPointerLeave={() => onActive(null)}
                        onFocus={() => onActive(part.id)}
                        onBlur={() => onActive(null)}
                        data-highlighted={activeNode === part.id ? 'true' : undefined}
                        className={`rounded-md border border-line px-3 py-2 text-sm data-[highlighted=true]:border-accent data-[highlighted=true]:bg-accent-soft ${focusRing}`}
                    >
                        <p className="font-medium text-ink">{part.label}</p>
                        {part.does && <p className="text-ink-muted">{part.does}</p>}
                        <p className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-xs text-ink-subtle">
                            {part.modules.length > 0
                                ? part.modules.map((module) =>
                                      module.slug ? (
                                          <Link key={module.path} href={`${docsBase}/${module.slug}`} className={`rounded-sm font-mono hover:text-accent ${focusRing}`}>
                                              {module.path}
                                          </Link>
                                      ) : (
                                          <span key={module.path} className="font-mono">
                                              {module.path}
                                          </span>
                                      ),
                                  )
                                : part.outside.length > 0 && <span>{`${t('diagrams.explain.outside')}: ${part.outside.join(', ')}`}</span>}
                        </p>
                    </li>
                ))}
            </ul>
        </div>
    );
}

type ViewProps = {
    diagram: Diagram;
    title: string;
    docsBase: string;
    legend: { label: string; swatch: string }[];
};

/**
 * An architecture diagram with its legend and, under it, what it tells you and the evidence for its arrows.
 * The box the pointer or focus is on is remembered here, so the evidence rows of its arrows light up.
 */
function ArchitectureView({ diagram, title, docsBase, legend }: ViewProps) {
    const [activeNode, setActiveNode] = useState<string | null>(null);

    return (
        <>
            <MermaidDiagram code={diagram.mermaid} title={title} modules={diagram.modules} docsBase={docsBase} onNodeActive={setActiveNode} />
            <DiagramLegend items={legend} />
            <DiagramMeaning diagram={diagram} docsBase={docsBase} activeNode={activeNode} />
        </>
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
 * A flow as a sequence diagram with, beside it, its numbered steps and the lines of code that do each one.
 * Hovering or focusing a step lights its message; hovering a message lights its step.
 */
function FlowCard({ flow }: { flow: Diagram }) {
    const { t } = useI18n();
    const [activeStep, setActiveStep] = useState<number | null>(null);
    const [activeMessage, setActiveMessage] = useState<string | null>(null);
    const highlight = useMemo(() => (activeStep === null ? undefined : { messages: [activeStep + 1] }), [activeStep]);
    const litStep = activeMessage?.startsWith('message:') ? Number(activeMessage.slice(8)) - 1 : activeStep;

    return (
        <article className="rounded-lg border border-line bg-surface p-4 shadow-card sm:p-6">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h3 className="font-display text-lg font-semibold text-ink">{flow.title}</h3>
                {flow.main && <span className="rounded-full bg-accent-soft px-2 py-0.5 text-xs font-medium text-accent">{t('diagrams.explain.mainFlow')}</span>}
            </div>
            {flow.explanation && <p className="mt-2 max-w-[70ch] text-sm leading-6 text-ink-muted">{flow.explanation}</p>}
            <div className="mt-4 flex flex-col gap-4">
                <MermaidDiagram code={flow.mermaid} title={flow.title} onNodeActive={setActiveMessage} highlight={highlight} />
                <div className="flex min-w-0 flex-col gap-3">
                    <StepList title={t('diagrams.explain.steps')} steps={flow.steps} shown={flow.steps} activeNode={null} activeStep={litStep} onActive={setActiveStep} columns />
                    <p className="text-xs text-ink-subtle">
                        {flow.source === 'fallback' ? t('diagrams.explain.flowFallback') : t('diagrams.explain.flowAi')}
                        {flow.source !== 'fallback' && flow.dropped > 0 && <> {t('diagrams.explain.flowDropped', { count: formatNumber(flow.dropped) })}</>}
                    </p>
                </div>
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
