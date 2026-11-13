import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode, RefObject } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import type { PresentationVideo } from '@/types/media';

/** What the player must know about the repository to name the video and link to its page. */
export type PlayerContext = {
    repositoryName: string;
    mediaHref: string;
};

type Session = PlayerContext & {
    video: PresentationVideo;
};

/** What to do when the browser has loaded the (new) file: where to continue and whether to play. */
type PendingStart = {
    time: number;
    shouldPlay: boolean;
    /** The length of the previous language's video; its time is kept only when the new length is within 20 %. */
    previousDuration: number | null;
};

type VideoPlayerValue = {
    session: Session | null;
    /** Whether a page slot is on screen, i.e. the video is shown in the page and not in the mini player. */
    isDocked: boolean;
    videoRef: RefObject<HTMLVideoElement | null>;
    start: (video: PresentationVideo, context: PlayerContext, at?: number) => void;
    seek: (video: PresentationVideo, context: PlayerContext, milliseconds: number) => void;
    changeVideo: (video: PresentationVideo) => void;
    close: () => void;
    setSlotElement: (element: HTMLElement | null) => void;
    setWellElement: (element: HTMLElement | null) => void;
};

const VideoPlayerContext = createContext<VideoPlayerValue | null>(null);

/** The share of difference between two languages' lengths under which the position is kept. */
const KEEP_POSITION_TOLERANCE = 0.2;

/** How long the dock/undock movement lasts, in milliseconds. */
const MOVE_MS = 220;

/**
 * Owns the ONE <video> of a repository visit. It lives in the workspace layout, which Inertia keeps between the
 * pages of a repository, so a change of section never unmounts it. The element is never moved in the DOM (that
 * would restart it): it is positioned (fixed) over a page's slot when the page has one, or over the well of the
 * mini player otherwise. The layout re-creates the provider for another repository, which closes the video.
 */
export function VideoPlayerProvider({ children }: { children: ReactNode }) {
    const [session, setSession] = useState<Session | null>(null);
    const [slotElement, setSlotElement] = useState<HTMLElement | null>(null);
    const [wellElement, setWellElement] = useState<HTMLElement | null>(null);
    const videoRef = useRef<HTMLVideoElement>(null);
    const pending = useRef<PendingStart | null>(null);

    const start = useCallback((video: PresentationVideo, context: PlayerContext, at = 0) => {
        pending.current = { time: at, shouldPlay: true, previousDuration: null };
        setSession({ ...context, video });
    }, []);

    const seek = useCallback(
        (video: PresentationVideo, context: PlayerContext, milliseconds: number) => {
            const element = videoRef.current;

            if (element && session?.video.language === video.language) {
                element.currentTime = milliseconds / 1000;
                void element.play().catch(() => undefined);
            } else {
                start(video, context, milliseconds / 1000);
            }
        },
        [session?.video.language, start],
    );

    const changeVideo = useCallback((video: PresentationVideo) => {
        const element = videoRef.current;

        if (!element) {
            return;
        }

        pending.current = {
            time: element.currentTime,
            shouldPlay: !element.paused,
            previousDuration: Number.isFinite(element.duration) ? element.duration : null,
        };
        setSession((current) => (current && current.video.src !== video.src ? { ...current, video } : current));
    }, []);

    const close = useCallback(() => {
        const element = videoRef.current;

        element?.pause();

        if (element && document.pictureInPictureElement === element) {
            void document.exitPictureInPicture().catch(() => undefined);
        }

        setSession(null);
    }, []);

    const isDocked = slotElement !== null;

    const value = useMemo<VideoPlayerValue>(
        () => ({ session, isDocked, videoRef, start, seek, changeVideo, close, setSlotElement, setWellElement }),
        [session, isDocked, start, seek, changeVideo, close],
    );

    return (
        <VideoPlayerContext.Provider value={value}>
            {children}
            {session && (
                <VideoHost
                    session={session}
                    videoRef={videoRef}
                    pending={pending}
                    targetElement={slotElement ?? wellElement}
                    isDocked={isDocked}
                    onTogglePlayback={togglePlayback}
                />
            )}
        </VideoPlayerContext.Provider>
    );
}

export function useVideoPlayer(): VideoPlayerValue {
    const value = useContext(VideoPlayerContext);

    if (value === null) {
        throw new Error('useVideoPlayer must be used inside VideoPlayerProvider.');
    }

    return value;
}

/** Whether the floating mini player is on screen, so a scrolling column can leave room for it. */
export function useIsMiniPlayerVisible(): boolean {
    const { session, isDocked } = useVideoPlayer();

    return session !== null && !isDocked;
}

function togglePlayback(element: HTMLVideoElement): void {
    if (element.paused) {
        void element.play().catch(() => undefined);
    } else {
        element.pause();
    }
}

type HostProps = {
    session: Session;
    videoRef: RefObject<HTMLVideoElement | null>;
    pending: RefObject<PendingStart | null>;
    targetElement: HTMLElement | null;
    isDocked: boolean;
    onTogglePlayback: (element: HTMLVideoElement) => void;
};

/**
 * The container of the <video>: always the same fixed box, whose rectangle follows the target (a page's slot or the
 * mini player's well) frame by frame, so it keeps up with scrolling, resizing and layout changes. Inside a
 * scrolling page the box is clipped to that page's scroll area, so the video never covers the top bar.
 */
function VideoHost({ session, videoRef, pending, targetElement, isDocked, onTogglePlayback }: HostProps) {
    const { t } = useI18n();
    const hostRef = useRef<HTMLDivElement>(null);
    const targetRef = useRef<HTMLElement | null>(targetElement);

    targetRef.current = targetElement;

    // The first play: the click that started it is still fresh, so the browser lets the sound start.
    useEffect(() => {
        void videoRef.current?.play().catch(() => undefined);
    }, [videoRef]);

    useEffect(() => {
        const host = hostRef.current;

        if (!host) {
            return;
        }

        let frame = 0;
        let lastTarget: HTMLElement | null = null;
        let lastGeometry = '';
        let moveTimer = 0;

        function follow() {
            const target = targetRef.current;

            if (host && target) {
                if (target !== lastTarget) {
                    const isFirst = lastTarget === null;
                    const reducesMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                    host.style.transition = isFirst || reducesMotion ? 'none' : `left ${MOVE_MS}ms, top ${MOVE_MS}ms, width ${MOVE_MS}ms, height ${MOVE_MS}ms`;
                    host.style.borderRadius = getComputedStyle(target).borderRadius;
                    window.clearTimeout(moveTimer);
                    moveTimer = window.setTimeout(() => {
                        host.style.transition = 'none';
                    }, MOVE_MS + 30);
                    lastTarget = target;
                }

                const box = target.getBoundingClientRect();
                const scrollArea = target.closest('[scroll-region]')?.getBoundingClientRect();
                const clip = scrollArea
                    ? `inset(${Math.max(0, scrollArea.top - box.top)}px ${Math.max(0, box.right - scrollArea.right)}px ${Math.max(0, box.bottom - scrollArea.bottom)}px ${Math.max(0, scrollArea.left - box.left)}px)`
                    : 'none';
                const geometry = `${box.left}|${box.top}|${box.width}|${box.height}|${clip}`;

                if (geometry !== lastGeometry) {
                    host.style.left = `${box.left}px`;
                    host.style.top = `${box.top}px`;
                    host.style.width = `${box.width}px`;
                    host.style.height = `${box.height}px`;
                    host.style.clipPath = clip;
                    host.style.visibility = 'visible';
                    lastGeometry = geometry;
                }
            }

            frame = requestAnimationFrame(follow);
        }

        frame = requestAnimationFrame(follow);

        return () => {
            cancelAnimationFrame(frame);
            window.clearTimeout(moveTimer);
        };
    }, []);

    // The browser has the file: continue where the previous version was (or at the asked second) and play if it did.
    function applyPendingStart(element: HTMLVideoElement) {
        const start = pending.current;
        pending.current = null;

        if (!start) {
            return;
        }

        const keepsPosition =
            start.previousDuration === null ||
            (Number.isFinite(element.duration) && Math.abs(element.duration - start.previousDuration) / start.previousDuration < KEEP_POSITION_TOLERANCE);

        if (keepsPosition && start.time > 0) {
            element.currentTime = start.time;
        }

        if (start.shouldPlay) {
            void element.play().catch(() => undefined);
        }
    }

    return (
        <div
            ref={hostRef}
            style={{ position: 'fixed', left: 0, top: 0, width: 0, height: 0, visibility: 'hidden' }}
            className="z-25 overflow-hidden bg-canvas"
        >
            <video
                ref={videoRef}
                src={session.video.src ?? undefined}
                poster={session.video.poster ?? undefined}
                controls={isDocked}
                playsInline
                preload="auto"
                aria-label={t('media.video.label', { repository: session.repositoryName })}
                onLoadedMetadata={(event) => applyPendingStart(event.currentTarget)}
                onClick={isDocked ? undefined : (event) => onTogglePlayback(event.currentTarget)}
                className="size-full object-contain"
            >
                {session.video.captions && (
                    <track key={session.video.language} kind="captions" src={session.video.captions} srcLang={session.video.language} label={session.video.language_label} default />
                )}
            </video>
        </div>
    );
}

type SlotProps = {
    className?: string;
    /** Shown while the video has not been started (the poster and its play button). */
    children?: ReactNode;
};

/**
 * The place of the video in a page. It is only a frame: the one <video> of the repository docks over it as soon
 * as it plays. Without a started video the slot shows its children.
 */
export function VideoSlot({ className, children }: SlotProps) {
    const { session, setSlotElement } = useVideoPlayer();

    return (
        <div ref={setSlotElement} className={className}>
            {session === null && children}
        </div>
    );
}
