import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { Diagram, DiagramEvidence, DiagramFindings } from '@/components/diagrams/types';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { formatNumber, formatPercent } from '@/lib/format';

type Props = {
    diagram: Diagram;
    /** Where the module pages live, e.g. "/repositories/1/docs". */
    docsBase: string;
    /** The box of the diagram that is hovered or focused: the evidence rows of its arrows are highlighted. */
    activeNode: string | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';
const codeChip = 'rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-[0.8125rem] text-ink';

/**
 * Under an architecture diagram: what it tells you (a reading, then the findings computed from the real
 * imports) and, collapsed, the evidence behind every arrow. A diagram drawn before the findings existed
 * has neither, and then nothing is shown (no empty headings).
 */
export default function DiagramMeaning({ diagram, docsBase, activeNode }: Props) {
    const i18n = useI18n();
    const { t } = i18n;
    const { findings, interpretation, evidence } = diagram;

    if (!findings && !interpretation) {
        return null;
    }

    const unit = unitOf(findings);
    const modulePages = new Map(Object.values(diagram.modules).map((module) => [module.path, module.slug]));
    const name = (path: string): ReactNode => <ModuleName key={path} path={path} slug={modulePages.get(path) ?? null} docsBase={docsBase} />;
    const reading = interpretation?.source === 'ai' && interpretation.reading ? { reading: withNames(interpretation.reading, name), worth: withNames(interpretation.worth ?? '', name) } : findings ? composedReading(i18n, diagram, findings, evidence, name, unit) : null;

    return (
        <div className="flex flex-col gap-4">
            <section aria-labelledby={`${diagram.type}-meaning`} className="rounded-lg border border-line bg-surface p-4 sm:p-5">
                <h3 id={`${diagram.type}-meaning`} className="font-display text-base font-semibold text-ink">
                    {t('diagrams.meaning.heading')}
                </h3>

                {reading && (
                    <div className="mt-2 flex max-w-[68ch] flex-col gap-2 text-sm leading-6 text-ink-muted">
                        <p>{reading.reading}</p>
                        {reading.worth && (
                            <p>
                                <span className="font-medium text-ink">{t('diagrams.meaning.worth')}: </span>
                                {reading.worth}
                            </p>
                        )}
                    </div>
                )}

                {interpretation && (
                    <p className="mt-2 text-xs text-ink-subtle">{interpretation.source === 'ai' ? t('diagrams.meaning.sourceAi') : t('diagrams.meaning.sourceComposed')}</p>
                )}

                {findings && <FindingsList findings={findings} name={name} unit={unit} />}
            </section>

            {evidence.length > 0 && <EvidenceTable evidence={evidence} activeNode={activeNode} />}
        </div>
    );
}

/** What the diagram's boxes are: modules, or files for a small repository (the wording below changes with it). */
type Unit = 'modules' | 'files';

function unitOf(findings: DiagramFindings | null): Unit {
    return findings?.unit === 'files' ? 'files' : 'modules';
}

/** The key of a sentence that names its boxes: the files wording has its own key under `diagrams.meaning.files`. */
function wording(unit: Unit, key: string): TranslationKey {
    return (unit === 'files' ? `diagrams.meaning.files.${key}` : `diagrams.meaning.${key}`) as TranslationKey;
}

/** A module path: a link to its documentation page when it has one, plain code otherwise. */
function ModuleName({ path, slug, docsBase }: { path: string; slug: string | null; docsBase: string }) {
    if (!slug) {
        return <code className={codeChip}>{path}</code>;
    }

    return (
        <Link href={`${docsBase}/${slug}`} className={`${codeChip} hover:bg-accent-soft hover:text-accent ${focusRing}`}>
            {path}
        </Link>
    );
}

/** Claude writes names between backticks; they become code (and links to the module page when there is one). */
function withNames(text: string, name: (path: string) => ReactNode): ReactNode[] {
    return text.split(/`([^`]+)`/).map((part, index) => (index % 2 === 1 ? name(part) : part));
}

/** Items separated by commas, each one a ReactNode. */
function commaList(items: ReactNode[]): ReactNode {
    return items.map((item, index) => (
        <span key={index}>
            {index > 0 && ', '}
            {item}
        </span>
    ));
}

/** A path of modules joined by arrows: a → b → a. */
function arrowPath(path: string[], name: (path: string) => ReactNode): ReactNode {
    return path.map((module, index) => (
        <span key={`${module}-${index}`}>
            {index > 0 && <span aria-hidden="true"> → </span>}
            {name(module)}
        </span>
    ));
}

/**
 * The reading when AI wrote none (AI off, failed or rejected): sentences composed from the findings and the
 * arrows' counts, in the interface language.
 */
function composedReading(i18n: ReturnType<typeof useI18n>, diagram: Diagram, findings: DiagramFindings, evidence: DiagramEvidence[], name: (path: string) => ReactNode, unit: Unit): { reading: ReactNode; worth: ReactNode } {
    const { rich, t, plural } = i18n;
    const imports = evidence.reduce((sum, row) => sum + row.imports, 0);
    const { coverage } = findings;
    const sentences: ReactNode[] = [];
    const values = { modules: plural(unit, coverage.shown_modules), imports: plural('imports', imports) };

    sentences.push(
        diagram.type === 'layers'
            ? rich('diagrams.meaning.composed.layers', { ...values, arrows: plural('arrows', evidence.length) })
            : rich(wording(unit, 'composed.components'), { ...values, arrows: plural('arrows', evidence.length) }),
    );

    if (coverage.shown_modules < coverage.total_modules) {
        sentences.push(t(wording(unit, 'composed.biggest')));
    }

    const [core] = findings.core ?? [];

    if (core) {
        sentences.push(rich(wording(unit, 'composed.core'), { module: name(core.module), importers: plural(unit, core.modules) }));
    }

    const [coordinator] = findings.coordinators ?? [];

    if (coordinator && coordinator.module !== core?.module) {
        sentences.push(rich(wording(unit, 'composed.coordinator'), { module: name(coordinator.module), others: plural(unit, coordinator.modules) }));
    }

    const [loop] = findings.cycles ?? [];
    const [pair] = findings.upward?.pairs ?? [];
    const [busiest] = findings.busiest ?? [];
    let worth: ReactNode = null;

    if (loop) {
        worth = rich(wording(unit, 'composed.loop'), { path: arrowPath([...loop.modules, loop.modules[0]], name) });
    } else if (pair) {
        worth = rich('diagrams.meaning.composed.upward', { pair: arrowPath([pair.from, pair.to], name) });
    } else if (busiest) {
        worth = rich(wording(unit, 'composed.busiest'), { module: name(busiest.module), commits: plural('commits', busiest.commits) });
    } else if (findings.cycles) {
        worth = t(wording(unit, 'composed.noLoop'));
    }

    return { reading: joinSentences(sentences), worth };
}

/** Sentences one after the other, with a space between them. */
function joinSentences(sentences: ReactNode[]): ReactNode {
    return sentences.map((sentence, index) => (
        <span key={index}>
            {index > 0 && ' '}
            {sentence}
        </span>
    ));
}

/** The findings as a short list: a label and what was found, each naming real modules. */
function FindingsList({ findings, name, unit }: { findings: DiagramFindings; name: (path: string) => ReactNode; unit: Unit }) {
    const { t, rich, plural } = useI18n();
    const rows: { key: string; label: string; hint?: string; content: ReactNode }[] = [];
    const modulesWith = (items: { module: string; modules: number }[], detailKey: 'imported' | 'importing') =>
        commaList(items.map((item) => rich('diagrams.meaning.item', { module: name(item.module), detail: t(wording(unit, detailKey), { count: plural(unit, item.modules) }) })));

    if (findings.core?.length) {
        rows.push({ key: 'core', label: t('diagrams.meaning.labels.core'), hint: t(wording(unit, 'core')), content: modulesWith(findings.core, 'imported') });
    }

    if (findings.coordinators?.length) {
        rows.push({ key: 'coordinators', label: t('diagrams.meaning.labels.coordinators'), hint: t(wording(unit, 'coordinators')), content: modulesWith(findings.coordinators, 'importing') });
    }

    if (findings.cycles) {
        rows.push({
            key: 'cycles',
            label: t('diagrams.meaning.labels.cycles'),
            content:
                findings.cycles.length === 0 ? (
                    t(wording(unit, 'cycles.none'))
                ) : (
                    <ul className="flex flex-col gap-1">
                        {findings.cycles.map((cycle) => {
                            const path = arrowPath([...cycle.modules, cycle.modules[0]], name);

                            return (
                                <li key={cycle.modules.join('>')}>
                                    {cycle.size > cycle.modules.length
                                        ? rich(wording(unit, 'cycles.part'), { path, count: formatNumber(cycle.size) })
                                        : rich('diagrams.meaning.cycles.loop', { path })}
                                </li>
                            );
                        })}
                    </ul>
                ),
        });
    }

    if (findings.upward) {
        rows.push({
            key: 'upward',
            label: t('diagrams.meaning.labels.upward'),
            content:
                findings.upward.count === 0
                    ? t('diagrams.meaning.upward.none')
                    : rich('diagrams.meaning.upward.some', {
                          imports: plural('imports', findings.upward.imports),
                          pairs: commaList(findings.upward.pairs.map((pair) => arrowPath([pair.from, pair.to], name))),
                      }),
        });
    }

    if (findings.isolated?.length) {
        rows.push({ key: 'isolated', label: t('diagrams.meaning.labels.isolated'), content: rich(wording(unit, 'isolated'), { modules: commaList(findings.isolated.map(name)) }) });
    }

    const { coverage } = findings;

    rows.push({
        key: 'coverage',
        label: t('diagrams.meaning.labels.coverage'),
        content: (
            <>
                {t(wording(unit, 'coverage.shown'), {
                    modules: formatNumber(coverage.shown_modules),
                    total: formatNumber(coverage.total_modules),
                    share: coverage.total_lines > 0 ? formatPercent(coverage.shown_lines / coverage.total_lines) : '–',
                })}
                {coverage.undrawn_imports > 0 && (
                    <>
                        {' '}
                        {t(wording(unit, 'coverage.undrawn'), { imports: formatNumber(coverage.undrawn_imports), total: formatNumber(coverage.total_imports) })}
                    </>
                )}
            </>
        ),
    });

    if (findings.busiest?.length) {
        rows.push({
            key: 'busiest',
            label: t('diagrams.meaning.labels.busiest'),
            hint: t(wording(unit, 'busiest')),
            content: commaList(findings.busiest.map((item) => rich('diagrams.meaning.item', { module: name(item.module), detail: plural('commits', item.commits) }))),
        });
    }

    return (
        <div className="mt-4 border-t border-line pt-3">
            <p className="text-small font-medium text-ink-subtle">{t('diagrams.meaning.findingsHeading')}</p>
            <dl className="mt-2 grid gap-x-6 gap-y-2.5 text-sm sm:grid-cols-[11rem_minmax(0,1fr)]">
                {rows.map((row) => (
                    <div key={row.key} className="contents">
                        <dt className="font-medium text-ink">
                            {row.label}
                            {row.hint && <span className="block text-xs font-normal text-ink-subtle">{row.hint}</span>}
                        </dt>
                        <dd className="leading-6 text-ink-muted">{row.content}</dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/** The labels of an arrow: modules are code, layers are plain words. */
function ArrowLabel({ row }: { row: DiagramEvidence }) {
    const isLayer = row.from.startsWith('layer_');
    const label = (text: string) => (isLayer ? <span className="font-medium text-ink">{text}</span> : <code className={codeChip}>{text}</code>);

    return (
        <span className="inline-flex flex-wrap items-center gap-x-1.5 gap-y-1">
            {label(row.from_label)}
            <span aria-hidden="true">→</span>
            {label(row.to_label)}
        </span>
    );
}

/** A file path, linked to GitHub at the diagram's commit when there is a commit. */
function FileLink({ path, url }: { path: string; url: string | null }) {
    const { t } = useI18n();
    const text = <span className="break-all">{path}</span>;

    if (!url) {
        return <span className="font-mono text-xs text-ink">{text}</span>;
    }

    return (
        <a href={url} target="_blank" rel="noopener noreferrer" className={`inline-flex items-baseline gap-1 rounded-sm font-mono text-xs text-ink hover:text-accent ${focusRing}`}>
            {text}
            <ExternalLinkIcon className="size-3 shrink-0 self-center text-ink-subtle" />
            <span className="sr-only"> {t('common.opensInNewTab')}</span>
        </a>
    );
}

/** The collapsed evidence: every arrow with its number of imports and up to three examples. */
function EvidenceTable({ evidence, activeNode }: { evidence: DiagramEvidence[]; activeNode: string | null }) {
    const { t, plural } = useI18n();
    const total = evidence.reduce((sum, row) => sum + row.imports, 0);

    return (
        <details className="group rounded-lg border border-line bg-surface">
            <summary className={`flex cursor-pointer items-center gap-2 rounded-lg px-4 py-3 text-sm font-medium text-ink select-none hover:bg-surface-2 ${focusRing}`}>
                <span aria-hidden="true" className="text-ink-subtle transition-transform group-open:rotate-90">
                    ▸
                </span>
                {t('diagrams.meaning.evidence.summary', { arrows: plural('arrows', evidence.length), imports: plural('imports', total) })}
            </summary>
            <div className="border-t border-line px-4 py-3">
                <p className="max-w-[68ch] text-xs text-ink-subtle">{t('diagrams.meaning.evidence.note')}</p>
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full min-w-[34rem] border-collapse text-left text-sm">
                        <thead>
                            <tr className="border-b border-line text-xs text-ink-subtle">
                                <th scope="col" className="py-2 pr-3 font-medium">
                                    {t('diagrams.meaning.evidence.arrow')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('diagrams.meaning.evidence.imports')}
                                </th>
                                <th scope="col" className="py-2 pl-3 font-medium">
                                    {t('diagrams.meaning.evidence.examples')}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {evidence.map((row) => (
                                <tr
                                    key={`${row.from}>${row.to}`}
                                    data-highlighted={activeNode !== null && row.nodes.includes(activeNode) ? 'true' : undefined}
                                    className="border-b border-line-subtle align-top last:border-0 data-[highlighted=true]:bg-accent-soft"
                                >
                                    <td className="py-2.5 pr-3">
                                        <ArrowLabel row={row} />
                                    </td>
                                    <td className="px-3 py-2.5 text-right tabular-nums text-ink">{formatNumber(row.imports)}</td>
                                    <td className="py-2.5 pl-3">
                                        <ul className="flex flex-col gap-1">
                                            {row.examples.map((example) => (
                                                <li key={`${example.from}:${example.line ?? ''}>${example.to}`} className="flex flex-wrap items-baseline gap-x-1.5">
                                                    <FileLink path={example.line ? `${example.from}:${example.line}` : example.from} url={example.url} />
                                                    {example.to && (
                                                        <>
                                                            <span aria-hidden="true" className="text-ink-subtle">
                                                                →
                                                            </span>
                                                            <FileLink path={example.to} url={example.target_url} />
                                                        </>
                                                    )}
                                                    {example.excerpt && <code className="block w-full truncate font-mono text-xs text-ink-subtle">{example.excerpt}</code>}
                                                </li>
                                            ))}
                                        </ul>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </details>
    );
}
