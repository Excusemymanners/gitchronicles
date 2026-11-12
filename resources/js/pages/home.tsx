import { Head, usePoll } from '@inertiajs/react';
import { useEffect } from 'react';
import AddRepositoryForm from '@/components/home/add-repository-form';
import Capabilities from '@/components/home/capabilities';
import RepositoryCard from '@/components/home/repository-card';
import PipelineRail from '@/components/pipeline-rail';
import { useI18n } from '@/hooks/use-i18n';
import AppLayout, { pageContainer } from '@/layouts/app-layout';
import { formatNumber } from '@/lib/format';
import type { CountKey } from '@/lang';
import { useCountUp } from '@/lib/motion';
import type { RepositoryCard as RepositoryCardData } from '@/types/home';

type Props = {
    /** Toate repository-urile adăugate de oricine, cele mai noi primele, cu faptele de pe carduri. */
    repositories: RepositoryCardData[];
};

/**
 * Prima pagină: ce face aplicația și formularul de adăugare, pașii procesării („Cum funcționează”),
 * capitolele pe care le primește fiecare repository și galeria repository-urilor adăugate.
 * Toate numerele vin din baza de date; textele fixe doar explică produsul.
 */
export default function Home({ repositories }: Props) {
    const { t } = useI18n();
    const hasProcessingRepository = repositories.some((repository) => repository.is_processing);

    // Cât timp măcar un repository se mai procesează, cerem lista din nou la fiecare 2 secunde
    // (doar prop-ul „repositories”); când toate sunt gata sau au eșuat, ne oprim.
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

            {/* Pe ecrane late: titlul și formularul în stânga, pașii procesării în dreapta. Pe telefon, unul sub altul. */}
            <section className={`grid gap-x-16 gap-y-14 pt-12 pb-16 sm:pt-16 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)] lg:pt-24 lg:pb-24 ${pageContainer}`}>
                <div>
                    <h1 className="max-w-[16ch] font-display text-display font-bold text-ink">{t('home.hero.title')}</h1>
                    <p className="mt-5 max-w-[58ch] text-base leading-7 text-ink-muted sm:mt-6 sm:text-lg sm:leading-8">
                        {t('home.hero.lead')}
                    </p>

                    <div className="mt-10 max-w-2xl">
                        <AddRepositoryForm />
                    </div>
                </div>

                <section aria-labelledby="how-heading" className="lg:pt-3">
                    <h2 id="how-heading" className="font-display text-lg font-semibold tracking-tight text-ink">
                        {t('home.how.title')}
                    </h2>
                    <p className="mt-1 text-sm text-ink-muted">{t('home.how.lead')}</p>
                    {/* Singura animație de la încărcarea paginii: linia „se scrie” de sus în jos, inel cu inel. */}
                    <PipelineRail animated className="mt-6" />
                </section>
            </section>

            <section aria-labelledby="capabilities-heading" className={`pb-16 lg:pb-24 ${pageContainer}`}>
                <h2 id="capabilities-heading" className="font-display text-heading font-semibold text-ink">
                    {t('home.capabilities.title')}
                </h2>
                <div className="mt-8">
                    <Capabilities />
                </div>
            </section>

            <section aria-labelledby="repositories-heading" className="flex-1 border-t border-line bg-surface/40 py-16 lg:py-20">
                <div className={pageContainer}>
                    <div className="flex flex-wrap items-end justify-between gap-x-8 gap-y-2">
                        <h2 id="repositories-heading" className="font-display text-heading font-semibold text-ink">
                            {t('home.repositories.title')}
                        </h2>
                        {repositories.length > 0 && <Totals repositories={repositories} />}
                    </div>

                    {repositories.length === 0 ? (
                        <div className="mt-8 rounded-lg border border-dashed border-line-strong px-6 py-14 text-center">
                            <p className="font-display text-lg font-semibold text-ink">{t('home.repositories.emptyTitle')}</p>
                            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-ink-muted">
                                {t('home.repositories.emptyText')}
                            </p>
                            <button
                                type="button"
                                onClick={() => document.getElementById('url')?.focus()}
                                className="pressable mt-6 inline-flex cursor-pointer items-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            >
                                {t('home.repositories.emptyAction')}
                            </button>
                        </div>
                    ) : (
                        <ul className="mt-8 grid gap-4 lg:grid-cols-2">
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
 * Totalurile galeriei, adunate din cardurile de mai jos: câte repository-uri, fișiere analizate și pagini scrise.
 * Numerele urcă o dată până la valoarea lor (useCountUp) și din nou când un repository termină de procesat.
 */
function Totals({ repositories }: { repositories: RepositoryCardData[] }) {
    const fileCount = repositories.reduce((sum, repository) => sum + repository.facts.file_count, 0);
    const documentCount = repositories.reduce((sum, repository) => sum + repository.facts.document_count, 0);

    const shownFiles = useCountUp(fileCount);
    const shownDocuments = useCountUp(documentCount);
    const { plural } = useI18n();

    // Substantivul se acordă cu numărul final („1 file”, „20 de fișiere”), nu cu cel care încă urcă:
    // formele de plural încep cu {count}, așa că tăiem primul cuvânt și punem în locul lui numărul animat.
    const nounOf = (count: number, key: CountKey) => plural(key, count).replace(/^\S+\s/, '');

    return (
        <p className="text-sm text-ink-muted">
            <strong className="font-medium text-ink tabular-nums">{repositories.length}</strong> {nounOf(repositories.length, 'repositories')},{' '}
            <strong className="font-medium text-ink tabular-nums">{formatNumber(shownFiles)}</strong> {nounOf(fileCount, 'filesAnalyzed')}
            {documentCount > 0 && (
                <>
                    , <strong className="font-medium text-ink tabular-nums">{formatNumber(shownDocuments)}</strong>{' '}
                    {nounOf(documentCount, 'pagesWritten')}
                </>
            )}
        </p>
    );
}
