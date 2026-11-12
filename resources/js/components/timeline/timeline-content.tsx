import { InfiniteScroll, router } from '@inertiajs/react';
import { useEffect, useId, useState } from 'react';
import type { ReactNode } from 'react';
import { RegenerateOffer } from '@/components/repository/chapter-empty-state';
import TextInput from '@/components/text-input';
import CommitRow from '@/components/timeline/commit-row';
import ContributorList from '@/components/timeline/contributor-list';
import HotspotList from '@/components/timeline/hotspot-list';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';
import { formatMonth, monthKey } from '@/lib/history-format';
import type { RepositoryItem } from '@/types';
import type { CommitItem, CommitPage, Contributor, HistorySummary, Hotspot, ProcessingRunItem } from '@/types/history';

export type TimelineContentProps = {
    repository: RepositoryItem;
    /** Cifrele istoricului importat; `null` până la primul import. */
    summary: HistorySummary | null;
    /** Commit-urile găsite (toate sau cele căutate), cele mai noi primele; paginile următoare se adaugă la cerere. */
    commits: CommitPage;
    /** Rulările GitChronicle, fiecare cu commit-ul pe care l-a procesat. */
    runs: ProcessingRunItem[];
    hotspots: Hotspot[];
    contributors: Contributor[];
    filters: { q: string };
};

/** Cât așteaptă căutarea după ultima tastă înainte să întrebe serverul. */
const SEARCH_DELAY_MS = 300;

/**
 * Capitolul „Istoric” al unui repository: cifrele principale, linia timpului cu commit-urile grupate pe luni
 * (cu rulările GitChronicle pe commit-urile procesate), căutarea, „Zone fierbinți” și „Contribuitori”.
 * Când e loc (containerul are cel puțin 48rem), ultimele două stau într-o coloană în dreapta.
 */
export default function TimelineContent({ repository, summary, commits, runs, hotspots, contributors, filters }: TimelineContentProps) {
    const { t, rich } = useI18n();

    if (summary === null) {
        return <EmptyHistory repository={repository} />;
    }

    return (
        <div className="@container flex flex-col gap-6">
            <KeyNumbers summary={summary} runCount={runs.length} />

            {/* Pe o singură coloană, secțiunile laterale ajung sub listă: legăturile duc direct la ele. */}
            <p className="text-sm text-ink-muted @3xl:hidden">
                {rich('timeline.furtherDown', {
                    hotspots: (
                        <a href="#zone-fierbinti" className="rounded-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                            {t('timeline.hotspots')}
                        </a>
                    ),
                    contributors: (
                        <a href="#contribuitori" className="rounded-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                            {t('timeline.contributors')}
                        </a>
                    ),
                })}
            </p>

            <div className="grid gap-8 @3xl:grid-cols-[minmax(0,1fr)_17rem]">
                <section aria-labelledby="timeline-commits-heading" className="min-w-0">
                    <h2 id="timeline-commits-heading" className="text-lg font-semibold tracking-tight">
                        {t('timeline.commits')}
                    </h2>
                    <SearchBox repositoryId={repository.id} initialQuery={filters.q} />
                    <CommitTimeline repository={repository} commits={commits.data} runs={runs} query={filters.q} />
                </section>

                <aside className="flex min-w-0 flex-col gap-8">
                    <AsideSection id="zone-fierbinti" title={t('timeline.hotspots')}>
                        <p className="mb-4 text-sm text-ink-muted">
                            {t('timeline.hotspotsExplanationCounted', { commits: formatNumber(summary.commit_count) })}
                        </p>
                        {hotspots.length > 0 ? (
                            <HotspotList repository={repository} hotspots={hotspots} />
                        ) : (
                            <p className="text-sm text-ink-subtle">{t('timeline.hotspotsEmpty')}</p>
                        )}
                    </AsideSection>

                    <AsideSection id="contribuitori" title={t('timeline.contributors')}>
                        <ContributorList contributors={contributors} total={summary.contributor_count} />
                    </AsideSection>
                </aside>
            </div>
        </div>
    );
}

/**
 * Ce afișăm cât timp nu există istoric importat: de ce lipsește și când apare. Un repository gata a fost
 * procesat fără etapa istoricului (de exemplu de o versiune mai veche): oferim „Regenerează tot”.
 */
function EmptyHistory({ repository }: { repository: RepositoryItem }) {
    const { t } = useI18n();
    let message = t('timeline.empty.default');

    if (repository.status === 'ready') {
        message = t('timeline.empty.ready');
    } else if (repository.status === 'failed') {
        message = t('timeline.empty.failed');
    } else if (repository.is_processing) {
        message = t('timeline.empty.processing');
    } else if (repository.cloned_at === null) {
        message = t('timeline.empty.notCloned');
    }

    return (
        <section aria-labelledby="timeline-empty-heading" className="rounded-lg border border-line bg-surface p-6 shadow-xs">
            <h2 id="timeline-empty-heading" className="text-base font-semibold text-ink">
                {t('timeline.title')}
            </h2>
            <p className="mt-2 text-sm text-ink-muted">{message}</p>
            {repository.status === 'ready' && <RegenerateOffer className="mt-4 text-sm text-ink-muted" />}
        </section>
    );
}

/** Cifrele de sus: câte commit-uri, câți oameni, câte rulări și perioada acoperită. */
function KeyNumbers({ summary, runCount }: { summary: HistorySummary; runCount: number }) {
    const { t } = useI18n();
    const firstMonth = formatMonth(monthKey(summary.first_commit_at), true);
    const lastMonth = formatMonth(monthKey(summary.last_commit_at), true);

    const numbers = [
        { label: t('timeline.numbers.commits'), value: formatNumber(summary.commit_count) },
        { label: t('timeline.numbers.contributors'), value: formatNumber(summary.contributor_count) },
        { label: t('timeline.numbers.runs'), value: formatNumber(runCount) },
        { label: t('timeline.numbers.period'), value: firstMonth === lastMonth ? lastMonth : `${firstMonth} – ${lastMonth}` },
    ];

    return (
        <dl className="grid grid-cols-2 gap-3 @2xl:grid-cols-4">
            {numbers.map(({ label, value }) => (
                <div key={label} className="rounded-lg border border-line bg-surface px-4 py-3 shadow-xs">
                    <dt className="text-sm text-ink-subtle">{label}</dt>
                    <dd className="mt-1 text-lg font-semibold tracking-tight text-ink tabular-nums">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/**
 * Câmpul de căutare: după o scurtă pauză în tastare cere serverului doar lista de commit-uri
 * (și filtrul), de la prima pagină (`reset`), fără să adauge o intrare nouă în istoricul browserului.
 */
function SearchBox({ repositoryId, initialQuery }: { repositoryId: number; initialQuery: string }) {
    const { t } = useI18n();
    const inputId = useId();
    const [query, setQuery] = useState(initialQuery);

    useEffect(() => {
        if (query.trim() === initialQuery) {
            return;
        }

        const timer = window.setTimeout(() => {
            router.get(`/repositories/${repositoryId}/timeline`, query.trim() === '' ? {} : { q: query.trim() }, {
                only: ['commits', 'filters'],
                reset: ['commits'],
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, SEARCH_DELAY_MS);

        return () => window.clearTimeout(timer);
    }, [query, initialQuery, repositoryId]);

    return (
        <div className="mt-3 mb-5">
            <label htmlFor={inputId} className="sr-only">
                {t('timeline.search.label')}
            </label>
            <TextInput
                id={inputId}
                type="search"
                value={query}
                maxLength={100}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t('timeline.search.placeholder')}
            />
        </div>
    );
}

type MonthGroup = { key: string; commits: CommitItem[] };

/** Commit-urile pe luni, în ordinea în care au venit (cele mai noi primele). */
function groupByMonth(commits: CommitItem[]): MonthGroup[] {
    const groups: MonthGroup[] = [];

    for (const commit of commits) {
        const key = monthKey(commit.authored_at);
        const lastGroup = groups[groups.length - 1];

        if (lastGroup?.key === key) {
            lastGroup.commits.push(commit);
        } else {
            groups.push({ key, commits: [commit] });
        }
    }

    return groups;
}

/**
 * Linia timpului. InfiniteScroll (Inertia) adaugă pagina următoare sub cele încărcate la apăsarea
 * butonului „Încarcă mai multe” (`manual`), fără să schimbe adresa paginii (`preserveUrl`).
 */
function CommitTimeline({ repository, commits, runs, query }: { repository: RepositoryItem; commits: CommitItem[]; runs: ProcessingRunItem[]; query: string }) {
    const { t } = useI18n();

    if (commits.length === 0) {
        return <p className="text-sm text-ink-subtle">{t('timeline.search.noMatch', { query })}</p>;
    }

    // Rulările fiecărui commit procesat, în ordinea primită (cea mai nouă prima).
    const runsBySha = new Map<string, ProcessingRunItem[]>();

    for (const run of runs) {
        if (run.sha !== null) {
            runsBySha.set(run.sha, [...(runsBySha.get(run.sha) ?? []), run]);
        }
    }

    return (
        <InfiniteScroll
            data="commits"
            manual
            preserveUrl
            className="flex flex-col gap-6"
            next={({ loading, fetch, hasMore }) =>
                hasMore && (
                    <button
                        type="button"
                        onClick={fetch}
                        disabled={loading}
                        className="mt-6 w-full cursor-pointer rounded-md bg-surface px-4 py-2 text-sm font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-wait disabled:opacity-60"
                    >
                        {loading ? t('timeline.loading') : t('timeline.loadMore')}
                    </button>
                )
            }
        >
            {groupByMonth(commits).map((group) => (
                <section key={group.key} aria-label={formatMonth(group.key)}>
                    <h3 className="mb-3 text-sm font-semibold text-ink">{formatMonth(group.key)}</h3>
                    <ol className="ml-1.5 border-l border-line">
                        {group.commits.map((commit) => (
                            <CommitRow key={commit.id} repository={repository} commit={commit} runs={runsBySha.get(commit.sha) ?? []} />
                        ))}
                    </ol>
                </section>
            ))}
        </InfiniteScroll>
    );
}

/** O secțiune din coloana laterală, cu ancoră (de exemplu #zone-fierbinti). */
function AsideSection({ id, title, children }: { id: string; title: string; children: ReactNode }) {
    return (
        <section id={id} aria-labelledby={`${id}-heading`} className="scroll-mt-6">
            <h2 id={`${id}-heading`} className="text-lg font-semibold tracking-tight">
                {title}
            </h2>
            <div className="mt-3">{children}</div>
        </section>
    );
}
