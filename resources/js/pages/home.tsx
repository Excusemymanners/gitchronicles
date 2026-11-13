import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import type { CSSProperties } from 'react';
import Button from '@/components/button';
import AddRepositoryForm from '@/components/home/add-repository-form';
import Promises from '@/components/home/promises';
import RepositoryCard from '@/components/home/repository-card';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout, { pageContainer } from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import type { CountKey } from '@/lang';
import { useCountUp } from '@/lib/motion';
import type { RepositoryCard as RepositoryCardData } from '@/types/home';

type Props = {
    /** Every repository anyone added, newest first, with the facts on their cards. */
    repositories: RepositoryCardData[];
};

/** The place of a hero part in its entrance (the stagger utility reads --i). */
function staggerAt(index: number): CSSProperties {
    return { '--i': index } as CSSProperties;
}

/** How many documented repositories the field offers as one-click examples. */
const EXAMPLE_COUNT = 3;

/**
 * The home page. The hero says what GitChronicle does in one headline and one sentence, and puts the link field in
 * the middle of the screen; under it, the four promises as one tight row; then the real preview of what a visitor
 * gets: the repositories already documented here, as cards with their real numbers.
 * Every number comes from the database; the fixed texts only explain the product.
 *
 * Motion: only the hero's parts enter, one after the other (animate-rise + stagger), on the first paint.
 */
export default function Home({ repositories }: Props) {
    const { t } = useI18n();
    const hasProcessingRepository = repositories.some((repository) => repository.is_processing);
    const examples = repositories
        .filter((repository) => repository.status === 'ready')
        .slice(0, EXAMPLE_COUNT)
        .map((repository) => repository.full_name);

    // While at least one repository is still being processed, ask for the list again every 2 seconds (only the
    // "repositories" prop); when all are done or failed, stop.
    const { start, stop } = usePoll(2000, { only: ['repositories'] }, { autoStart: false });

    useEffect(() => {
        if (hasProcessingRepository) {
            start();
        } else {
            stop();
        }
    }, [hasProcessingRepository, start, stop]);

    return (
        <AppLayout>
            <Head title={t('home.title')}>
                <meta head-key="description" name="description" content={t('home.metaDescription')} />
            </Head>

            <section aria-labelledby="hero-title" className="relative isolate overflow-hidden">
                {/* The quiet backdrop: a faint dot grid that fades out from the top (pure CSS, see hero-grid). */}
                <div aria-hidden="true" className="hero-grid pointer-events-none absolute inset-0 -z-10" />

                <div className={`pt-16 pb-14 text-center sm:pt-24 sm:pb-20 lg:pt-28 ${pageContainer}`}>
                    <h1
                        id="hero-title"
                        style={staggerAt(0)}
                        className="stagger mx-auto max-w-5xl animate-rise font-display text-display font-semibold text-ink"
                    >
                        {t('home.hero.title')}
                    </h1>
                    <p
                        style={staggerAt(1)}
                        className="stagger mx-auto mt-6 max-w-[58ch] animate-rise text-lead text-ink-muted"
                    >
                        {t('home.hero.lead')}
                    </p>

                    <div style={staggerAt(2)} className="stagger mx-auto mt-10 max-w-3xl animate-rise text-left sm:mt-12">
                        <AddRepositoryForm examples={examples} />
                    </div>
                </div>
            </section>

            <div className={`pb-14 lg:pb-16 ${pageContainer}`}>
                <Promises />
            </div>

            <section aria-labelledby="repositories-heading" className="flex-1 border-t border-line-subtle bg-surface/50 py-12 lg:py-16">
                <div className={pageContainer}>
                    <div className="flex flex-wrap items-baseline justify-between gap-x-8 gap-y-2">
                        <h2 id="repositories-heading" className="font-display text-heading font-semibold text-ink">
                            {t('home.repositories.title')}
                        </h2>
                        {repositories.length > 0 && <Totals repositories={repositories} />}
                    </div>

                    {repositories.length === 0 ? (
                        <div className="mt-8 rounded-lg border border-dashed border-line-strong px-6 py-14 text-center">
                            <p className="font-display text-lg font-semibold text-ink">{t('home.repositories.emptyTitle')}</p>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-ink-muted">{t('home.repositories.emptyText')}</p>
                            <Button className="mt-6" onClick={() => document.getElementById('url')?.focus()}>
                                {t('home.repositories.emptyAction')}
                            </Button>
                        </div>
                    ) : (
                        <ul className="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {repositories.map((repository) => (
                                <RepositoryCard key={repository.id} repository={repository} />
                            ))}
                        </ul>
                    )}
                </div>
            </section>
        </AppLayout>
    );
}

/**
 * The gallery's totals, added up from the cards below: how many repositories, files analysed and pages written.
 * The numbers count up once to their value (useCountUp), and again when a repository finishes processing.
 */
function Totals({ repositories }: { repositories: RepositoryCardData[] }) {
    const fileCount = repositories.reduce((sum, repository) => sum + repository.facts.file_count, 0);
    const documentCount = repositories.reduce((sum, repository) => sum + repository.facts.document_count, 0);

    const shownFiles = useCountUp(fileCount);
    const shownDocuments = useCountUp(documentCount);
    const { plural } = useI18n();

    // The noun agrees with the final number ("1 file", "20 de fișiere"), not with the one still counting up: the plural
    // forms start with {count}, so we cut the first word and put the animated number in its place.
    const nounOf = (count: number, key: CountKey) => plural(key, count).replace(/^\S+\s/, '');

    return (
        <p className="text-sm text-ink-muted">
            <strong className="font-semibold text-ink tabular-nums">{repositories.length}</strong> {nounOf(repositories.length, 'repositories')},{' '}
            <strong className="font-semibold text-ink tabular-nums">{formatNumber(shownFiles)}</strong> {nounOf(fileCount, 'filesAnalyzed')}
            {documentCount > 0 && (
                <>
                    , <strong className="font-semibold text-ink tabular-nums">{formatNumber(shownDocuments)}</strong> {nounOf(documentCount, 'pagesWritten')}
                </>
            )}
        </p>
    );
}
