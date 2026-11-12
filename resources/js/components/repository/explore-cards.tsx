import { useId } from 'react';
import type { ReactNode } from 'react';
import SeverityBadge, { SEVERITIES } from '@/components/consulting/severity-badge';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import ChapterLink from '@/components/workspace/chapter-link';
import { ArrowRightIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate, formatNumber, formatRelative, shortSha } from '@/lib/format';
import type { RepositoryItem, WorkspaceElements } from '@/types';
import type { Activity } from '@/types/history';
import type { RepositoryShowcase } from '@/types/showcase';

type Props = {
    repository: RepositoryItem;
    showcase: RepositoryShowcase;
    /** The workspace's cards data: the architecture preview and the report's counts. */
    elements: WorkspaceElements;
    activity: Activity | null;
};

/**
 * "Explore": one card per part of the repository's chronicle — Documentation, Architecture, History, Report. Each
 * is one link with one real number and a small preview (the first page titles, a diagram thumbnail, what the last
 * update changed, the findings by severity). A card whose data does not exist is left out.
 */
export default function ExploreCards({ repository, showcase, elements, activity }: Props) {
    const { t, plural } = useI18n();
    const headingId = useId();
    const { documentation, diagram_count: diagramCount } = showcase;
    const architecture = elements.architecture;
    const counts = elements.consulting?.counts ?? null;
    const problemCount = counts === null ? 0 : SEVERITIES.filter((severity) => severity !== 'info').reduce((sum, severity) => sum + counts[severity], 0);

    if (documentation === null && diagramCount === 0 && activity === null && counts === null) {
        return null;
    }

    return (
        <section aria-labelledby={headingId} className="border-t border-line pt-8">
            <h2 id={headingId} className="font-display text-heading font-semibold text-ink">
                {t('overview.showcase.exploreTitle')}
            </h2>

            <ul className="mt-5 grid grid-cols-[repeat(auto-fit,minmax(min(100%,15rem),1fr))] gap-4">
                {documentation !== null && (
                    <ExploreCard title={t('overview.showcase.docsTitle')} figure={plural('pages', documentation.page_count)} href={documentation.href} linkLabel={t('overview.showcase.docsLink')}>
                        <ul className="flex flex-col gap-1 text-[0.8125rem] text-ink-muted">
                            {documentation.pages.map((page) => (
                                <li key={page.href} className="truncate" title={page.title}>
                                    {page.title}
                                </li>
                            ))}
                        </ul>
                    </ExploreCard>
                )}

                {diagramCount > 0 && (
                    <ExploreCard
                        title={t('overview.showcase.diagramsTitle')}
                        figure={plural('diagrams', diagramCount)}
                        href={architecture?.href ?? `/repositories/${repository.id}/diagrams`}
                        linkLabel={t('overview.showcase.diagramsLink')}
                    >
                        {architecture && (
                            // Shrunk to fit whole; the card links to the full view.
                            <div aria-hidden="true" className="pointer-events-none h-28 overflow-hidden rounded-md border border-line bg-canvas p-2">
                                <MermaidDiagram code={architecture.mermaid} title={architecture.title} compact />
                            </div>
                        )}
                    </ExploreCard>
                )}

                {activity !== null && (
                    <ExploreCard
                        title={t('overview.showcase.historyTitle')}
                        figure={plural('commits', activity.commit_count)}
                        href={`/repositories/${repository.id}/timeline`}
                        linkLabel={t('overview.showcase.historyLink')}
                    >
                        <HistoryPreview showcase={showcase} />
                    </ExploreCard>
                )}

                {counts !== null && elements.consulting && (
                    <ExploreCard title={t('overview.showcase.reportTitle')} figure={plural('problems', problemCount)} href={elements.consulting.href} linkLabel={t('overview.showcase.reportLink')}>
                        <ul className="flex flex-wrap gap-1.5">
                            {SEVERITIES.filter((severity) => severity !== 'info').map((severity) => (
                                <li key={severity}>
                                    <SeverityBadge severity={severity} label={t('overview.showcase.severityCount', { severity: t(`severities.${severity}`), count: formatNumber(counts[severity]) })} />
                                </li>
                            ))}
                        </ul>
                    </ExploreCard>
                )}
            </ul>
        </section>
    );
}

/**
 * What the newest update changed (files, modules, rewritten pages, from which commit to which) or, for a repository
 * never updated, its newest commit. Only the numbers that are known are said.
 */
function HistoryPreview({ showcase }: { showcase: RepositoryShowcase }) {
    const { t, plural } = useI18n();
    const change = showcase.last_change;

    if (change !== null) {
        const facts = [
            change.files_changed !== null ? plural('filesChanged', change.files_changed) : null,
            change.modules_affected !== null ? plural('modulesAffected', change.modules_affected) : null,
            change.documents_regenerated !== null ? plural('pagesRewritten', change.documents_regenerated) : null,
        ].filter((fact): fact is string => fact !== null);

        return (
            <div className="text-[0.8125rem] text-ink-muted">
                <p className="text-ink">
                    {t('overview.showcase.lastUpdate')}{' '}
                    <span className="font-mono text-xs">
                        {shortSha(change.from_sha)}…{shortSha(change.to_sha)}
                    </span>
                </p>
                {facts.length > 0 && (
                    <ul className="mt-1 flex flex-col gap-0.5">
                        {facts.map((fact) => (
                            <li key={fact}>{fact}</li>
                        ))}
                    </ul>
                )}
            </div>
        );
    }

    if (showcase.latest_commit !== null) {
        const commit = showcase.latest_commit;

        return (
            <div className="text-[0.8125rem] text-ink-muted">
                <p>{t('overview.showcase.latestCommit')}</p>
                <p className="mt-1 line-clamp-2 text-ink" title={commit.subject}>
                    {commit.subject}
                </p>
                <p className="mt-1">
                    <span className="font-mono text-xs">{shortSha(commit.sha)}</span>{' '}
                    <time dateTime={commit.authored_at} title={formatDate(commit.authored_at)}>
                        {formatRelative(commit.authored_at)}
                    </time>
                </p>
            </div>
        );
    }

    return null;
}

type CardProps = {
    title: string;
    /** The card's one real number, already worded ("25 pages"). */
    figure: string;
    href: string;
    linkLabel: string;
    children: ReactNode;
};

/** One "Explore" card: the whole card is the link (stretched with ::after), its focus ring drawn on the card. */
function ExploreCard({ title, figure, href, linkLabel, children }: CardProps) {
    const titleId = useId();

    return (
        <li>
            <article
                aria-labelledby={titleId}
                className="relative flex h-full flex-col rounded-xl border border-line bg-surface p-5 hover:border-line-strong has-[a:focus-visible]:outline-2 has-[a:focus-visible]:outline-offset-2 has-[a:focus-visible]:outline-accent motion-safe:transition-colors"
            >
                <h3 id={titleId} className="font-sans text-sm font-semibold text-ink">
                    {title}
                </h3>
                <p className="mt-1 font-display text-2xl font-semibold tracking-tight text-ink tabular-nums">{figure}</p>
                <div className="mt-4 min-w-0 flex-1">{children}</div>
                <ChapterLink
                    href={href}
                    className="mt-4 inline-flex items-center gap-1.5 text-[0.8125rem] font-medium text-accent outline-none after:absolute after:inset-0 after:rounded-xl hover:text-accent-hover"
                >
                    {linkLabel}
                    <ArrowRightIcon />
                </ChapterLink>
            </article>
        </li>
    );
}
