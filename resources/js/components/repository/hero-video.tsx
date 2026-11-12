import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PlayIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatTimecode } from '@/lib/format';
import type { RepositoryItem } from '@/types';
import type { MediaLanguage, PresentationMedia, PresentationVideo } from '@/types/media';

type Props = {
    repository: RepositoryItem;
    media: PresentationMedia;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** The vertical 9:16 frame every state of the video sits in, so the hero keeps its shape. */
const frameClasses = 'relative mx-auto aspect-9/16 w-full max-w-72 overflow-hidden rounded-2xl lg:max-w-none';

/**
 * The video that explains the repository, in the Overview's hero: vertical (9:16), its real poster with a large
 * play button and its length; a click plays it right here with the browser's own controls. With a video in both
 * English and Romanian, a switch picks the language. Without a ready video the frame says honestly what is
 * happening (being made, failed, not made yet) and offers the action a visitor may take — never a fake poster.
 */
export default function HeroVideo({ repository, media }: Props) {
    const { t } = useI18n();
    const readyVideos = media.videos.filter((video) => video.status === 'ready' && video.src !== null);
    const [language, setLanguage] = useState<MediaLanguage>(
        readyVideos.find((video) => video.language === media.default_language)?.language ?? readyVideos[0]?.language ?? media.default_language,
    );
    const video = readyVideos.find((item) => item.language === language) ?? readyVideos[0] ?? null;

    if (video === null) {
        return <VideoState repository={repository} media={media} />;
    }

    return (
        <figure className="flex flex-col gap-3">
            {/* The key starts the player again (poster first) when the language changes. */}
            <VideoPlayer key={video.language} video={video} repositoryName={repository.full_name} />

            <figcaption className="flex flex-wrap items-center justify-between gap-2 text-[0.8125rem] text-ink-muted">
                <Link href={`/repositories/${repository.id}/media`} className={`rounded-sm hover:text-ink hover:underline ${focusRing}`}>
                    {t('overview.showcase.videoScenes')}
                </Link>

                {readyVideos.length > 1 && (
                    <div role="group" aria-label={t('overview.showcase.videoLanguage')} className="inline-flex rounded-md bg-surface-2 p-0.5 ring-1 ring-line ring-inset">
                        {readyVideos.map((item) => (
                            <button
                                key={item.language}
                                type="button"
                                lang={item.language}
                                aria-pressed={item.language === video.language}
                                onClick={() => setLanguage(item.language)}
                                className={`cursor-pointer rounded-sm px-2.5 py-0.5 text-xs font-medium ${focusRing} ${
                                    item.language === video.language ? 'bg-surface text-ink shadow-card' : 'text-ink-muted hover:text-ink'
                                }`}
                            >
                                {item.language_label}
                            </button>
                        ))}
                    </div>
                )}
            </figcaption>
        </figure>
    );
}

/**
 * The poster with the play button until a click, then the <video> with native controls, playing inline.
 * A poster that cannot be loaded (a missing file) falls back to a plain frame, not a broken-image icon.
 */
function VideoPlayer({ video, repositoryName }: { video: PresentationVideo; repositoryName: string }) {
    const { t } = useI18n();
    const [isPlaying, setPlaying] = useState(false);
    const [hasPoster, setHasPoster] = useState(video.poster !== null);

    if (isPlaying && video.src) {
        return (
            <div className={`${frameClasses} bg-surface-2`}>
                <video
                    src={video.src}
                    poster={hasPoster && video.poster ? video.poster : undefined}
                    controls
                    autoPlay
                    playsInline
                    aria-label={t('overview.showcase.videoLabel', { repository: repositoryName })}
                    className="size-full object-contain"
                >
                    {video.captions && <track kind="captions" src={video.captions} srcLang={video.language} label={video.language_label} default />}
                </video>
            </div>
        );
    }

    return (
        <button
            type="button"
            onClick={() => setPlaying(true)}
            className={`group ${frameClasses} block cursor-pointer border border-line bg-surface-2 text-left shadow-float ${focusRing}`}
        >
            {hasPoster && video.poster && <img src={video.poster} alt="" onError={() => setHasPoster(false)} className="absolute inset-0 size-full object-cover" />}

            <span className="absolute inset-0 grid place-items-center">
                <span className="grid size-16 place-items-center rounded-full bg-canvas/85 text-ink shadow-float backdrop-blur-sm motion-safe:transition-transform motion-safe:duration-(--motion-fast) group-hover:scale-105">
                    <PlayIcon className="ml-1 size-7" />
                </span>
            </span>

            {video.duration_ms !== null && (
                <span className="absolute right-3 bottom-3 rounded-md bg-canvas/85 px-2 py-0.5 text-xs font-medium text-ink tabular-nums backdrop-blur-sm">
                    {formatTimecode(video.duration_ms)}
                </span>
            )}

            <span className="sr-only">
                {t('overview.showcase.playVideo', { repository: repositoryName })}
                {video.duration_ms !== null && `, ${formatTimecode(video.duration_ms)}`}
            </span>
        </button>
    );
}

/**
 * The video frame without a ready video: being made (it updates by itself), failed (with the reason), or not made
 * yet. A logged-in visitor can start or retry it; a guest is told who can.
 */
function VideoState({ repository, media }: Props) {
    const { t } = useI18n();
    const failed = media.videos.find((video) => video.status === 'failed') ?? null;
    const isWorking = media.videos.some((video) => video.status === 'pending' || video.status === 'running');
    const canStart = media.can_generate && repository.status === 'ready' && !isWorking;

    let title = t('overview.showcase.videoNone');
    let text = repository.status === 'ready' ? (media.can_generate ? t('overview.showcase.videoNoneCanMake') : t('overview.showcase.videoNoneLogIn')) : t('overview.showcase.videoNoneNotReady');

    if (isWorking) {
        title = t('overview.showcase.videoWorking');
        text = t('overview.showcase.videoWorkingText');
    } else if (failed) {
        title = t('overview.showcase.videoFailed');
        text = failed.error ?? '';
    }

    return (
        <div className={`${frameClasses} flex flex-col items-center justify-center gap-3 border border-dashed border-line-strong bg-surface px-6 text-center`}>
            <p role={isWorking ? 'status' : undefined} className="flex items-center gap-2 text-sm font-medium text-ink">
                {isWorking && <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />}
                {title}
            </p>
            {text !== '' && <p className="text-[0.8125rem] leading-relaxed text-ink-muted">{text}</p>}

            {canStart && (
                <Form action={`/repositories/${repository.id}/media/generate`} method="post" options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <button
                            type="submit"
                            disabled={processing}
                            className={`pressable mt-1 inline-flex cursor-pointer items-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                        >
                            {processing ? t('workspace.starting') : failed ? t('workspace.retry') : t('overview.showcase.videoMake')}
                        </button>
                    )}
                </Form>
            )}
        </div>
    );
}
