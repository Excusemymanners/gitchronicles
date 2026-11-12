import { Form, Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import SlidesSection from '@/components/media/slides-section';
import VideoSection from '@/components/media/video-section';
import { useI18n } from '@/hooks/use-i18n';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';
import type { MediaLanguage, PresentationMedia } from '@/types/media';

type Props = RepositoryPageProps & {
    /** Video-urile (câte unul pe limbă) și slide-urile repository-ului (PresentationMediaResource în Laravel). */
    media: PresentationMedia;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Pagina „Video”: video-ul de prezentare al repository-ului (în engleză și în română, cu scenele și sursa
 * fiecărei propoziții) și slide-urile, făcute din datele lui reale, pe coada „media”. Un utilizator
 * autentificat le poate porni sau reface de aici; cât timp se lucrează, pagina arată starea reală și se
 * reîmprospătează singură.
 */
export default function RepositoriesMedia({ repository, media }: Props) {
    const { t } = useI18n();
    // Cât timp ceva e în coadă sau se randează, cerem serverului la fiecare 3 secunde doar prop-ul „media”.
    const { start, stop } = usePoll(3000, { only: ['media'] }, { autoStart: false });

    useEffect(() => {
        if (media.is_working) {
            start();
        } else {
            stop();
        }
    }, [media.is_working, start, stop]);

    // Când randarea s-a terminat, cerem și spațiul de lucru: cardurile din „Elemente” și cuprinsul se schimbă.
    const wasWorking = useRef(media.is_working);

    useEffect(() => {
        if (wasWorking.current && !media.is_working) {
            router.reload({ only: ['workspace'] });
        }

        wasWorking.current = media.is_working;
    }, [media.is_working]);

    // Limba aleasă pentru player, subtitrări, descărcare și lista de surse; la început, cea implicită.
    const [language, setLanguage] = useState<MediaLanguage>(media.default_language);
    const video = media.videos.find((item) => item.language === language) ?? media.videos[0] ?? null;

    const hasNothing = media.videos.length === 0 && media.slides === null;
    const hasFailure = media.videos.some((item) => item.status === 'failed') || media.slides?.status === 'failed';
    const isStale = media.videos.some((item) => item.is_stale) || media.slides?.is_stale === true;

    let actionLabel = t('media.regenerate');

    if (hasNothing) {
        actionLabel = t('media.generate');
    } else if (hasFailure) {
        actionLabel = t('media.retry');
    }

    const languageSwitch =
        media.videos.length > 1 && video ? (
            <div role="group" aria-label={t('media.languageGroup')} className="ml-auto inline-flex rounded-md bg-surface-2 p-0.5 ring-1 ring-line ring-inset">
                {media.videos.map((item) => (
                    <button
                        key={item.language}
                        type="button"
                        lang={item.language}
                        aria-pressed={item.language === video.language}
                        onClick={() => setLanguage(item.language)}
                        className={`cursor-pointer rounded-sm px-3 py-1 text-sm font-medium ${focusRing} ${
                            item.language === video.language ? 'bg-surface text-ink shadow-xs' : 'text-ink-muted hover:text-ink'
                        }`}
                    >
                        {item.language_label}
                    </button>
                ))}
            </div>
        ) : null;

    return (
        <RepositoryLayout>
            <Head title={t('media.pageTitle', { repository: repository.full_name })} />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <h1 className="font-brand text-2xl font-bold tracking-tight text-ink">{t('media.title')}</h1>

                {media.can_generate && (
                    <Form action={`/repositories/${repository.id}/media/generate`} method="post" options={{ preserveScroll: true }}>
                        {({ processing }) => (
                            <button
                                type="submit"
                                disabled={processing}
                                className={`pressable inline-flex cursor-pointer items-center rounded-md px-3 py-1.5 text-sm font-semibold shadow-xs disabled:cursor-not-allowed disabled:opacity-60 ${focusRing} ${
                                    hasNothing || hasFailure
                                        ? 'bg-accent-strong text-on-accent hover:bg-accent-strong-hover'
                                        : 'bg-surface text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2'
                                }`}
                            >
                                {processing ? t('media.starting') : actionLabel}
                            </button>
                        )}
                    </Form>
                )}
            </div>

            <p className="mt-2 text-sm text-ink-muted">
                {t('media.intro')}
            </p>

            {media.is_working && (
                <p role="status" className="mt-4 flex items-center gap-2 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink">
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                    {t('media.working')}
                </p>
            )}

            {!media.is_working && isStale && media.can_generate && (
                <p className="mt-4 rounded-lg border border-warning-line bg-warning-soft px-4 py-3 text-sm text-warning-ink">
                    {t('media.stale')}
                </p>
            )}

            {hasNothing ? (
                <div className="mt-6 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                    <p className="font-medium text-ink">{t('media.emptyTitle')}</p>
                    <p className="mt-1">
                        {repository.status !== 'ready' && t('media.emptyNotReady')}
                        {repository.status === 'ready' && media.can_generate && t('media.emptyCanGenerate')}
                        {repository.status === 'ready' && !media.can_generate && t('media.emptyLogIn')}
                    </p>
                </div>
            ) : (
                <div className="mt-8 flex flex-col gap-10">
                    {video && <VideoSection key={video.language} video={video} repositoryName={repository.full_name} languageSwitch={languageSwitch} />}
                    {media.slides && <SlidesSection slides={media.slides} />}
                </div>
            )}
        </RepositoryLayout>
    );
}
