import { useId, useRef } from 'react';
import type { ReactNode } from 'react';
import DownloadLink from '@/components/media/download-link';
import MediaStatusBadge from '@/components/media/media-status-badge';
import SceneList from '@/components/media/scene-list';
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
                    Video de prezentare
                </h2>
                <MediaStatusBadge status={video.status} label={video.status_label} />
                {languageSwitch}
            </div>

            {video.status === 'failed' && (
                <p role="alert" className="mt-4 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink">
                    <span className="font-semibold">Video-ul nu a putut fi generat.</span> {video.error}
                </p>
            )}

            {(video.status === 'pending' || video.status === 'running') && (
                <div className="mt-4 grid gap-6 sm:grid-cols-[17rem_minmax(0,1fr)]">
                    <div aria-hidden="true" className="aspect-9/16 w-full max-w-68 rounded-2xl border border-line bg-surface-2 motion-safe:animate-pulse" />
                    <p className="text-sm text-ink-muted">
                        {video.status === 'pending'
                            ? 'Video-ul așteaptă la rând. Randarea pornește imediat ce workerul e liber.'
                            : 'Se înregistrează vocea și se randează cadrele peste pagina de GitHub. Durează în jur de un minut.'}
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
                                aria-label={`Video de prezentare pentru ${repositoryName}`}
                                className="block aspect-9/16 w-full"
                            >
                                <source src={video.src} type="video/mp4" />
                                {video.captions && <track kind="captions" src={video.captions} srcLang={video.language} label={video.language_label} default />}
                                Browserul tău nu poate reda video-ul. Îl poți descărca mai jos.
                            </video>
                        </div>

                        <div className="min-w-0">
                            <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
                                {video.duration_ms !== null && (
                                    <>
                                        <dt className="text-ink-subtle">Durată</dt>
                                        <dd className="text-ink">{formatDuration(video.duration_ms)}, vertical (1080 × 1920)</dd>
                                    </>
                                )}
                                {video.generated_at && (
                                    <>
                                        <dt className="text-ink-subtle">Generat</dt>
                                        <dd className="text-ink">{formatDate(video.generated_at)}</dd>
                                    </>
                                )}
                                <dt className="text-ink-subtle">Texte</dt>
                                <dd className="text-ink">
                                    {video.writer === 'ai'
                                        ? 'Scrise de Claude doar din sursele repository-ului; fiecare propoziție are citatul ei mai jos.'
                                        : 'Luate direct din descriere, din README și din cifre, fără AI.'}
                                </dd>
                                <dt className="text-ink-subtle">Voce</dt>
                                <dd className="text-ink">{video.voice ? `${video.voice} (sinteză vocală, pe server)` : 'Fără voce: doar muzică și subtitrări.'}</dd>
                                <dt className="text-ink-subtle">Muzică</dt>
                                <dd className="text-ink">Compusă de aplicație (generată de program, fără drepturi de autor ale altcuiva).</dd>
                            </dl>

                            {video.note && <p className="mt-3 text-sm text-ink-muted">{video.note}</p>}
                            {video.capture_note && <p className="mt-3 text-sm text-ink-muted">{video.capture_note} Video-ul folosește scene tipografice.</p>}

                            {video.is_stale && (
                                <p className="mt-3 rounded-lg border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                                    Repository-ul are un commit mai nou decât cel din care a fost făcut acest video.
                                </p>
                            )}

                            <div className="mt-4 flex flex-wrap gap-2">
                                <DownloadLink href={video.download} label="Descarcă MP4" sizeBytes={video.size_bytes} variant="primary" />
                            </div>
                        </div>
                    </div>

                    {video.scenes.length > 0 && (
                        <div className="mt-8">
                            <h3 className="text-base font-semibold text-ink">De unde vine fiecare propoziție</h3>
                            <p className="mt-1 mb-3 text-sm text-ink-subtle">
                                Scenele video-ului, cu citatul exact pe care se bazează fiecare. Apasă pe timp ca să vezi scena.
                            </p>
                            <SceneList scenes={video.scenes} onSeek={seek} />
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
