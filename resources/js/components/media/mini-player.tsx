import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { PauseIcon, OpenPageIcon, PictureInPictureIcon, VolumeOffIcon, VolumeOnIcon } from '@/components/media/player-icons';
import { useVideoPlayer } from '@/components/media/video-player';
import { CloseIcon, PlayIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatTimecode } from '@/lib/format';

const buttonClasses =
    'grid size-8 md:size-7 shrink-0 cursor-pointer place-items-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * The small floating player shown on every section of a repository that has no slot for the video, once the
 * visitor has started it. On a phone it is a compact bar above the bottom bar; from "md" a 9:16 card in the
 * bottom-left corner (the Ask button is on the right). Only its close button ends the video.
 */
export default function MiniPlayer() {
    const { session, isDocked } = useVideoPlayer();

    if (session === null || isDocked) {
        return null;
    }

    return <MiniBar />;
}

function MiniBar() {
    const { t } = useI18n();
    const { session, videoRef, setWellElement, close } = useVideoPlayer();
    const element = videoRef.current;
    const [state, setState] = useState({ isPaused: element?.paused ?? false, isMuted: element?.muted ?? false, time: element?.currentTime ?? 0, duration: element?.duration ?? 0 });

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        function read() {
            if (video) {
                setState({ isPaused: video.paused, isMuted: video.muted || video.volume === 0, time: video.currentTime, duration: video.duration });
            }
        }

        const events = ['play', 'pause', 'volumechange', 'timeupdate', 'loadedmetadata', 'durationchange', 'seeked'];

        read();
        events.forEach((name) => video.addEventListener(name, read));

        return () => events.forEach((name) => video.removeEventListener(name, read));
    }, [videoRef, session?.video.src]);

    if (session === null) {
        return null;
    }

    const video = videoRef.current;
    const canUsePictureInPicture = typeof document !== 'undefined' && document.pictureInPictureEnabled === true;
    const hasDuration = Number.isFinite(state.duration) && state.duration > 0;

    function togglePlayback() {
        if (video?.paused) {
            void video.play().catch(() => undefined);
        } else {
            video?.pause();
        }
    }

    function toggleMute() {
        if (video) {
            video.muted = !state.isMuted;
        }
    }

    function togglePictureInPicture() {
        if (!video) {
            return;
        }

        if (document.pictureInPictureElement === video) {
            void document.exitPictureInPicture().catch(() => undefined);
        } else {
            void video.requestPictureInPicture().catch(() => undefined);
        }
    }

    return (
        <section
            aria-label={t('media.mini.region')}
            className="shrink-0 border-t border-line bg-canvas px-3 py-2 md:fixed md:bottom-4 md:left-4 md:z-20 md:w-45 md:rounded-2xl md:border md:bg-surface md:p-2 md:shadow-float"
        >
            <div className="flex items-center gap-2 md:flex-col md:items-stretch md:gap-2">
                {/* The video itself docks over this well (see video-player.tsx). */}
                <div ref={setWellElement} className="aspect-9/16 h-16 shrink-0 rounded-lg bg-surface-2 md:h-auto md:w-full" />

                <div className="flex min-w-0 flex-1 flex-wrap items-center justify-between gap-x-1 md:flex-none">
                    <button type="button" onClick={togglePlayback} aria-label={state.isPaused ? t('media.mini.play') : t('media.mini.pause')} className={buttonClasses}>
                        {state.isPaused ? <PlayIcon className="size-4" /> : <PauseIcon />}
                    </button>

                    <button type="button" onClick={toggleMute} aria-pressed={state.isMuted} aria-label={state.isMuted ? t('media.mini.unmute') : t('media.mini.mute')} className={buttonClasses}>
                        {state.isMuted ? <VolumeOffIcon /> : <VolumeOnIcon />}
                    </button>

                    {canUsePictureInPicture && (
                        <button type="button" onClick={togglePictureInPicture} aria-label={t('media.mini.pictureInPicture')} className={buttonClasses}>
                            <PictureInPictureIcon />
                        </button>
                    )}

                    <Link href={session.mediaHref} aria-label={t('media.mini.openPage')} className={buttonClasses}>
                        <OpenPageIcon />
                    </Link>

                    <button type="button" onClick={close} aria-label={t('media.mini.close')} className={buttonClasses}>
                        <CloseIcon className="size-4" />
                    </button>

                    <TimeLabel>
                        {formatTimecode(state.time * 1000)}
                        {hasDuration && ` / ${formatTimecode(state.duration * 1000)}`}
                    </TimeLabel>
                </div>
            </div>
        </section>
    );
}

function TimeLabel({ children }: { children: ReactNode }) {
    return <span className="order-first px-1 text-xs text-ink-muted tabular-nums md:w-full md:text-center">{children}</span>;
}
