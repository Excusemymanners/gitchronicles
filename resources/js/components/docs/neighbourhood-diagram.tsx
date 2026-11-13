import { Link, router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { shortSha } from '@/lib/format';
import type { ModuleNeighbour, ModuleNeighbourhood } from '@/types/docs';

type Props = {
    neighbourhood: ModuleNeighbourhood;
    /** The commit the imports were read at, for the caption. */
    commit: string | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const NODE_WIDTH = 190;
const NODE_HEIGHT = 34;
const ROW = 46;
const GAP = 64;
/** Room above the columns for their titles. */
const TOP = 26;
const WIDTH = NODE_WIDTH * 3 + GAP * 2;
const MAX_LABEL = 24;

/** A long path keeps its end (the part that tells modules apart): "…/billing/invoices". */
function shorten(path: string): string {
    return path.length <= MAX_LABEL ? path : `…${path.slice(path.length - MAX_LABEL + 1)}`;
}

/** What a row of a column holds: a neighbour module, or the "+ N more" note. */
type Row = { kind: 'module'; neighbour: ModuleNeighbour } | { kind: 'more'; count: number };

function rowsOf(neighbours: ModuleNeighbour[], more: number): Row[] {
    return [...neighbours.map((neighbour): Row => ({ kind: 'module', neighbour })), ...(more > 0 ? [{ kind: 'more', count: more } as Row] : [])];
}

/** Opens a module page without a reload, like an Inertia link, but keeps the browser's own handling of new tabs. */
function visit(event: MouseEvent, href: string) {
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return;
    }

    event.preventDefault();
    router.visit(href);
}

/**
 * "Where this module sits": the module in the middle, the modules that import it on the left, the modules it imports
 * on the right, the number of imports on each arrow (at most 6 neighbours a side, the rest counted). Drawn from the
 * stored import graph. On a narrow screen the same data is a list: a diagram of this size would be too small to read.
 */
export default function NeighbourhoodDiagram({ neighbourhood, commit }: Props) {
    const { t } = useI18n();
    const left = rowsOf(neighbourhood.incoming, neighbourhood.more_incoming);
    const right = rowsOf(neighbourhood.outgoing, neighbourhood.more_outgoing);
    const rows = Math.max(left.length, right.length, 1);
    const height = rows * ROW;
    const centerY = height / 2;
    const columnTop = (count: number) => (height - count * ROW) / 2 + (ROW - NODE_HEIGHT) / 2;
    const centerX = NODE_WIDTH + GAP;
    const rightX = NODE_WIDTH * 2 + GAP * 2;

    return (
        <figure className="mt-8">
            <h2 className="sr-only">{t('docs.visuals.neighbourhoodTitle')}</h2>

            <div className="hidden rounded-lg border border-line bg-surface p-4 sm:block">
                <svg
                    viewBox={`0 0 ${WIDTH} ${height + TOP}`}
                    role="group"
                    aria-label={t('docs.visuals.neighbourhoodLabel', {
                        module: neighbourhood.module,
                    })}
                    className="mx-auto h-auto w-full"
                >
                    <defs>
                        <marker
                            id="neighbour-arrow"
                            viewBox="0 0 10 10"
                            refX="9"
                            refY="5"
                            markerWidth="7"
                            markerHeight="7"
                            orient="auto-start-reverse"
                        >
                            <path d="M0 0L10 5L0 10z" className="fill-ink-subtle" />
                        </marker>
                    </defs>

                    <g transform={`translate(0 ${TOP})`}>
                        {left.map((row, index) => {
                            const y = columnTop(left.length) + index * ROW;

                            return row.kind === 'module' ? (
                                <g key={`l-${row.neighbour.path}`}>
                                    <Edge x1={NODE_WIDTH} y1={y + NODE_HEIGHT / 2} x2={centerX} y2={centerY} weight={row.neighbour.weight} />
                                    <Node x={0} y={y} label={row.neighbour.path} href={row.neighbour.href} />
                                </g>
                            ) : (
                                <MoreNode
                                    key="l-more"
                                    x={0}
                                    y={y}
                                    label={t('docs.visuals.moreModules', {
                                        count: row.count,
                                    })}
                                />
                            );
                        })}

                        <g>
                            <rect
                                x={centerX}
                                y={centerY - NODE_HEIGHT / 2 - 4}
                                width={NODE_WIDTH}
                                height={NODE_HEIGHT + 8}
                                rx={8}
                                className="fill-accent-soft stroke-accent"
                                strokeWidth={1.5}
                            />
                            <text x={centerX + NODE_WIDTH / 2} y={centerY + 4.5} textAnchor="middle" className="fill-ink text-[14px] font-semibold">
                                {shorten(neighbourhood.module)}
                                <title>{neighbourhood.module}</title>
                            </text>
                        </g>

                        {right.map((row, index) => {
                            const y = columnTop(right.length) + index * ROW;

                            return row.kind === 'module' ? (
                                <g key={`r-${row.neighbour.path}`}>
                                    <Edge x1={centerX + NODE_WIDTH} y1={centerY} x2={rightX} y2={y + NODE_HEIGHT / 2} weight={row.neighbour.weight} />
                                    <Node x={rightX} y={y} label={row.neighbour.path} href={row.neighbour.href} />
                                </g>
                            ) : (
                                <MoreNode
                                    key="r-more"
                                    x={rightX}
                                    y={y}
                                    label={t('docs.visuals.moreModules', {
                                        count: row.count,
                                    })}
                                />
                            );
                        })}
                    </g>

                    <text x={0} y={14} className="fill-ink-subtle text-[12px]">
                        {t('docs.visuals.importedBy')}
                    </text>
                    <text x={rightX} y={14} className="fill-ink-subtle text-[12px]">
                        {t('docs.visuals.imports')}
                    </text>
                </svg>
            </div>

            <div className="grid gap-4 rounded-lg border border-line bg-surface p-4 sm:hidden">
                <NeighbourList title={t('docs.visuals.importedBy')} rows={left} />
                <p className="font-mono text-sm font-semibold break-all text-ink">{neighbourhood.module}</p>
                <NeighbourList title={t('docs.visuals.imports')} rows={right} />
            </div>

            <figcaption className="mt-2 text-[0.8125rem] text-ink-subtle">
                {t('docs.visuals.drawnCaption', {
                    sha: commit ? shortSha(commit) : '',
                })} · {t('docs.visuals.arrowsNote')}
            </figcaption>
        </figure>
    );
}

/** One side of the diagram as a list, for narrow screens. */
function NeighbourList({ title, rows: list }: { title: string; rows: Row[] }) {
    const { t, plural } = useI18n();

    if (list.length === 0) {
        return null;
    }

    return (
        <div>
            <p className="text-[0.8125rem] font-medium text-ink-subtle">{title}</p>
            <ul className="mt-1 space-y-1">
                {list.map((row) =>
                    row.kind === 'module' ? (
                        <li key={row.neighbour.path} className="flex items-baseline justify-between gap-3 text-sm">
                            {row.neighbour.href ? (
                                <Link
                                    href={row.neighbour.href}
                                    prefetch
                                    className={`rounded-sm font-mono break-all text-accent hover:underline ${focusRing}`}
                                >
                                    {row.neighbour.path}
                                </Link>
                            ) : (
                                <span className="font-mono break-all text-ink">{row.neighbour.path}</span>
                            )}
                            <span className="shrink-0 text-ink-subtle">{plural('imports', row.neighbour.weight)}</span>
                        </li>
                    ) : (
                        <li key="more" className="text-sm text-ink-subtle">
                            {t('docs.visuals.moreModules', {
                                count: row.count,
                            })}
                        </li>
                    ),
                )}
            </ul>
        </div>
    );
}

/** An arrow between two boxes with the number of imports on it. */
function Edge({ x1, y1, x2, y2, weight }: { x1: number; y1: number; x2: number; y2: number; weight: number }) {
    const { plural } = useI18n();
    const middleX = (x1 + x2) / 2;
    const middleY = (y1 + y2) / 2;

    return (
        <g>
            <title>{plural('imports', weight)}</title>
            <path
                d={`M${x1} ${y1} C${middleX} ${y1} ${middleX} ${y2} ${x2 - 2} ${y2}`}
                fill="none"
                className="stroke-ink-subtle"
                strokeWidth={1.25}
                markerEnd="url(#neighbour-arrow)"
            />
            <rect x={middleX - 14} y={middleY - 9} width={28} height={18} rx={9} className="fill-surface-2 stroke-line" />
            <text x={middleX} y={middleY + 4} textAnchor="middle" className="fill-ink text-[11px] font-medium">
                {weight}
            </text>
        </g>
    );
}

/** A neighbour module's box; a link to its page when it has one. */
function Node({ x, y, label, href }: { x: number; y: number; label: string; href: string | null }) {
    const content = (
        <>
            <rect
                x={x}
                y={y}
                width={NODE_WIDTH}
                height={NODE_HEIGHT}
                rx={7}
                className={`fill-surface stroke-line-strong ${href ? 'group-hover:stroke-accent' : ''}`}
            />
            <text
                x={x + NODE_WIDTH / 2}
                y={y + NODE_HEIGHT / 2 + 4.5}
                textAnchor="middle"
                className={`text-[13px] ${href ? 'fill-accent' : 'fill-ink'}`}
            >
                {shorten(label)}
            </text>
        </>
    );

    return href ? (
        <a
            href={href}
            onClick={(event) => visit(event, href)}
            aria-label={label}
            className={`group cursor-pointer rounded-sm outline-none focus-visible:[&_rect]:stroke-accent focus-visible:[&_rect]:stroke-2`}
        >
            <title>{label}</title>
            {content}
        </a>
    ) : (
        <g>
            <title>{label}</title>
            {content}
        </g>
    );
}

function MoreNode({ x, y, label }: { x: number; y: number; label: string }) {
    return (
        <g>
            <rect x={x} y={y} width={NODE_WIDTH} height={NODE_HEIGHT} rx={7} fill="none" className="stroke-line-strong" strokeDasharray="4 3" />
            <text x={x + NODE_WIDTH / 2} y={y + NODE_HEIGHT / 2 + 4.5} textAnchor="middle" className="fill-ink-subtle text-[12px]">
                {label}
            </text>
        </g>
    );
}
