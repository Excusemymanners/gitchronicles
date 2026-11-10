import { Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import SlidesSection from '@/components/media/slides-section';
import VideoSection from '@/components/media/video-section';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';
import type { PresentationMedia } from '@/types/media';

type Props = RepositoryPageProps & {
    /** Video-ul și slide-urile repository-ului (PresentationMediaResource în Laravel). */
    media: PresentationMedia;
};

/**
 * Pagina „Video”: video-ul de prezentare al repository-ului (cu scenele și sursa fiecărei propoziții)
 * și slide-urile, ambele făcute din datele lui reale, pe coada „media”, după ce repository-ul e gata.
 */
export default function RepositoriesMedia({ repository, media }: Props) {
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

    const hasNothing = media.video === null && media.slides === null;

    return (
        <RepositoryLayout>
            <Head title={`Video · ${repository.full_name}`} />

            <h1 className="font-brand text-2xl font-bold tracking-tight text-ink">Video și slide-uri</h1>
            <p className="mt-2 text-sm text-ink-muted">
                Un video scurt, în stilul reel-urilor, și o prezentare pe slide-uri despre ce face proiectul. Tot ce apare în ele vine din
                datele reale ale repository-ului: README, documentația generată, GitHub, analiza codului și istoricul git.
            </p>

            {media.is_working && (
                <p role="status" className="mt-4 flex items-center gap-2 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink">
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                    Se generează… Pagina se actualizează singură când sunt gata.
                </p>
            )}

            {hasNothing ? (
                <div className="mt-6 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                    <p className="font-medium text-ink">Video-ul și slide-urile nu au fost generate încă.</p>
                    <p className="mt-1">
                        {repository.status === 'ready'
                            ? 'Se fac automat după fiecare procesare a repository-ului, pe o coadă separată.'
                            : 'Se fac automat după ce repository-ul termină de procesat.'}
                    </p>
                </div>
            ) : (
                <div className="mt-8 flex flex-col gap-10">
                    {media.video && <VideoSection video={media.video} repositoryName={repository.full_name} />}
                    {media.slides && <SlidesSection slides={media.slides} />}
                </div>
            )}
        </RepositoryLayout>
    );
}
