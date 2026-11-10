import { useId } from 'react';
import DownloadLink from '@/components/media/download-link';
import MediaStatusBadge from '@/components/media/media-status-badge';
import { countLabel, formatDate } from '@/lib/format';
import type { PresentationSlides } from '@/types/media';

type Props = {
    slides: PresentationSlides;
};

/**
 * Slide-urile repository-ului: titlul fiecărui slide și descărcările (PDF pentru citit și trimis,
 * PPTX pentru PowerPoint sau Keynote). Sunt făcute din aceleași date ca video-ul.
 */
export default function SlidesSection({ slides }: Props) {
    const headingId = useId();

    return (
        // Id-ul e ancora capitolului „Slide-uri” din cuprins (WorkspaceResource::SLIDES_SECTION).
        <section aria-labelledby={headingId} id="slide-uri" className="scroll-mt-6">
            <div className="flex flex-wrap items-center gap-3">
                <h2 id={headingId} className="text-lg font-semibold text-ink">
                    Slide-uri
                </h2>
                <MediaStatusBadge status={slides.status} label={slides.status_label} />
            </div>

            {slides.status === 'failed' && (
                <p role="alert" className="mt-4 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink">
                    <span className="font-semibold">Slide-urile nu au putut fi generate.</span> {slides.error}
                </p>
            )}

            {(slides.status === 'pending' || slides.status === 'running') && (
                <p className="mt-4 text-sm text-ink-muted">Slide-urile se generează; apar aici singure când sunt gata.</p>
            )}

            {slides.status === 'ready' && (
                <div className="mt-4 rounded-lg border border-line bg-surface p-4 shadow-xs sm:p-6">
                    <p className="text-sm text-ink-muted">
                        {countLabel(slides.titles.length, 'slide', 'slide-uri')}, făcute din aceleași date reale ca video-ul
                        {slides.generated_at && <>, generate pe {formatDate(slides.generated_at)}</>}.
                    </p>

                    <ol className="mt-4 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
                        {slides.titles.map((title, index) => (
                            <li key={`${index}-${title}`} className="flex items-baseline gap-2 text-ink">
                                <span aria-hidden="true" className="w-5 shrink-0 text-right text-xs text-ink-subtle tabular-nums">
                                    {index + 1}
                                </span>
                                <span className="min-w-0 truncate">{title}</span>
                            </li>
                        ))}
                    </ol>

                    {slides.is_stale && (
                        <p className="mt-4 rounded-lg border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                            Repository-ul are un commit mai nou decât cel din care au fost făcute aceste slide-uri.
                        </p>
                    )}

                    <div className="mt-5 flex flex-wrap gap-2">
                        <DownloadLink href={slides.downloads.pdf} label="Descarcă PDF" sizeBytes={slides.size_bytes} variant="primary" />
                        <DownloadLink href={slides.downloads.pptx} label="Descarcă PPTX" />
                    </div>
                </div>
            )}
        </section>
    );
}
