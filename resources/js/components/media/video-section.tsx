import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import DownloadLink from '@/components/media/download-link';
import MediaStatusBadge from '@/components/media/media-status-badge';
import SceneList from '@/components/media/scene-list';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate } from '@/lib/format';
import { formatDuration } from '@/lib/history-format';
import type { PresentationVideo } from '@/types/media';

type Props = {
    video: PresentationVideo;
    /** Comutatorul de limbă (English / Română), când video-ul există în mai multe limbi. */
    languageSwitch?: ReactNode;
    /** „proprietar/nume”, pentru numele video-ului citit de cititoarele de ecran. */
    repositoryName: string;
};

/**
 * Video-ul de prezentare în limba aleasă: playerul vertical (9:16, ca un reel) cu poster și subtitrări, ce știm despre
 * el (durata, cine a scris textele, vocea, muzica), descărcarea și lista scenelor cu sursele lor.
 * Cât timp se randează sau dacă a eșuat, în locul playerului apare starea lui.
 */
export default function VideoSection({ video, repositoryName, languageSwitch = null }: Props) {
    const { t } = useI18n();
    const headingId = useId();
    const videoRef = useRef<HTMLVideoElement>(null);

    // Butonul cu timpul unei scene mută video-ul la începutul ei și îl pornește.
    function seek(milliseconds: number) {
        const element = videoRef.current;

        if (element) {
            element.currentTime = milliseconds / 1000;
            void element.play();
        }
    }

    return (
        <section aria-labelledby={headingId} id="video" className="scroll-mt-6">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id={headingId} className="text-lg font-semibold text-ink">
                    {t('media.video.title')}
                </h2>
                <MediaStatusBadge status={video.status} label={video.status_label} />
                {languageSwitch}
            </div>

            {video.status === 'failed' && (
                <p role="alert" className="mt-4 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink">
                    <span className="font-semibold">{t('media.video.failed')}</span> {video.error}
                </p>
            )}

            {(video.status === 'pending' || video.status === 'running') && (
                <div className="mt-4 grid gap-6 sm:grid-cols-[17rem_minmax(0,1fr)]">
                    <div aria-hidden="true" className="aspect-9/16 w-full max-w-68 rounded-2xl border border-line bg-surface-2 motion-safe:animate-pulse" />
                    <p className="text-sm text-ink-muted">
                        {video.status === 'pending' ? t('media.video.pending') : t('media.video.running')}
                    </p>
                </div>
            )}

            {video.status === 'ready' && video.src && (
                <>
                    <div className="mt-4 grid gap-6 sm:grid-cols-[17rem_minmax(0,1fr)]">
                        <div className="w-full max-w-68 overflow-hidden rounded-2xl border border-line bg-canvas">
                            <video
                                ref={videoRef}
                                controls
                                playsInline
                                preload="metadata"
                                poster={video.poster ?? undefined}
                                aria-label={t('media.video.label', { repository: repositoryName })}
                                className="block aspect-9/16 w-full"
                            >
                                <source src={video.src} type="video/mp4" />
                                {video.captions && <track kind="captions" src={video.captions} srcLang={video.language} label={video.language_label} default />}
                                {t('media.video.unsupported')}
                            </video>
                        </div>

                        <div className="min-w-0">
                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                                {video.duration_ms !== null && (
                                    <>
                                        <dt className="text-ink-subtle">{t('media.video.duration')}</dt>
                                        <dd className="text-ink">{t('media.video.durationValue', { duration: formatDuration(video.duration_ms) })}</dd>
                                    </>
                                )}
                                {video.generated_at && (
                                    <>
                                        <dt className="text-ink-subtle">{t('media.video.generated')}</dt>
                                        <dd className="text-ink">{formatDate(video.generated_at)}</dd>
                                    </>
                                )}
                                <dt className="text-ink-subtle">{t('media.video.texts')}</dt>
                                <dd className="text-ink">
                                    {video.writer === 'ai' ? t('media.video.textsAi') : t('media.video.textsTemplate')}
                                </dd>
                                <dt className="text-ink-subtle">{t('media.video.voice')}</dt>
                                <dd className="text-ink">{video.voice ? t('media.video.voiceValue', { voice: video.voice }) : t('media.video.noVoice')}</dd>
                                <dt className="text-ink-subtle">{t('media.video.music')}</dt>
                                <dd className="text-ink">{t('media.video.musicValue')}</dd>
                            </dl>

                            {video.note && <p className="mt-3 text-sm text-ink-muted">{video.note}</p>}
                            {video.capture_note && <p className="mt-3 text-sm text-ink-muted">{t('media.video.captureNote', { note: video.capture_note })}</p>}

                            {video.is_stale && (
                                <p className="mt-3 rounded-lg border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                                    {t('media.video.stale')}
                                </p>
                            )}

                            <div className="mt-4 flex flex-wrap gap-2">
                                <DownloadLink href={video.download} label={t('media.video.download')} sizeBytes={video.size_bytes} variant="primary" />
                            </div>
                        </div>
                    </div>

                    {video.scenes.length > 0 && (
                        <div className="mt-8">
                            <h3 className="text-base font-semibold text-ink">{t('media.video.sources')}</h3>
                            <p className="mt-1 mb-3 text-sm text-ink-subtle">
                                {t('media.video.sourcesText')}
                            </p>
                            <SceneList scenes={video.scenes} onSeek={seek} />
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
