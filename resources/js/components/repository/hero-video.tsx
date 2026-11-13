import { Form, Link } from '@inertiajs/react';
import { useState } from 'react';
import { useVideoPlayer, VideoSlot } from '@/components/media/video-player';
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
    const player = useVideoPlayer();
    const readyVideos = media.videos.filter((video) => video.status === 'ready' && video.src !== null);
    const [chosenLanguage, setLanguage] = useState<MediaLanguage>(
        readyVideos.find((video) => video.language === media.default_language)?.language ?? readyVideos[0]?.language ?? media.default_language,
    );
    // While a video plays, its language is the one shown (the visitor may have changed it on another page).
    const language = player.session?.video.language ?? chosenLanguage;
    const video = readyVideos.find((item) => item.language === language) ?? readyVideos[0] ?? null;

    function chooseLanguage(item: PresentationVideo) {
        setLanguage(item.language);

        if (player.session) {
            player.changeVideo(item);
        }
    }

    if (video === null) {
        return <VideoState repository={repository} media={media} />;
    }

    return (
        <figure className="flex flex-col gap-3">
            <VideoPlayer video={video} repository={repository} />

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
                                onClick={() => chooseLanguage(item)}
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
 * The poster with the play button until a click. Then the page only keeps the frame (the slot): the one <video>
 * of the repository, owned by the workspace, docks over it and keeps playing when the visitor leaves the page.
 * A poster that cannot be loaded (a missing file) falls back to a plain frame, not a broken-image icon.
 */
function VideoPlayer({ video, repository }: { video: PresentationVideo; repository: RepositoryItem }) {
    const { t } = useI18n();
    const player = useVideoPlayer();
    const [hasPoster, setHasPoster] = useState(video.poster !== null);
    const repositoryName = repository.full_name;

    return (
        <VideoSlot className={`${frameClasses} border border-line-subtle bg-surface-2`}>
            <button
                type="button"
                onClick={() => player.start(video, { repositoryName, mediaHref: `/repositories/${repository.id}/media` })}
                className={`group absolute inset-0 block cursor-pointer text-left ${focusRing}`}
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
        </VideoSlot>
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
