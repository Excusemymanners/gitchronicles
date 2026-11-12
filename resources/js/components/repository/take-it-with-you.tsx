import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { CheckIcon, CopyIcon, DownloadIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatFileSize } from '@/lib/format';
import type { PresentationMedia } from '@/types/media';
import type { RepositoryShowcase } from '@/types/showcase';

type Props = {
    readme: RepositoryShowcase['readme'];
    media: PresentationMedia;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const actionClasses = `pressable inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-surface px-2.5 py-1 text-[0.8125rem] font-medium whitespace-nowrap text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`;

/**
 * "Take it with you": the files GitChronicle made for the repository, in one compact row — the README (copy it or
 * download README.md), the slides (PDF, PPTX) and the video (one MP4 per language), each with its real size when
 * it is known. Only what exists is shown; with nothing ready the whole row is left out.
 */
export default function TakeItWithYou({ readme, media }: Props) {
    const { t } = useI18n();
    const headingId = useId();
    const slides = media.slides?.status === 'ready' ? media.slides : null;
    const hasSlides = slides !== null && (slides.downloads.pdf !== null || slides.downloads.pptx !== null);
    const videos = media.videos.filter((video) => video.download !== null);

    if (readme === null && !hasSlides && videos.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby={headingId} className="border-t border-line pt-8">
            <h2 id={headingId} className="font-display text-heading font-semibold text-ink">
                {t('overview.showcase.takeTitle')}
            </h2>
            <p className="mt-1 text-sm text-ink-muted">{t('overview.showcase.takeText')}</p>

            <ul className="mt-5 flex flex-col divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface lg:flex-row lg:divide-x lg:divide-y-0">
                {readme !== null && (
                    <Deliverable name="README.md" isFileName detail={formatFileSize(readme.size_bytes)}>
                        <CopyReadmeButton url={readme.download} />
                        <a href={readme.download} download className={actionClasses}>
                            <DownloadIcon className="size-3.5" />
                            {t('overview.showcase.download')}
                        </a>
                    </Deliverable>
                )}

                {hasSlides && slides && (
                    <Deliverable name={t('overview.showcase.slides')} detail={slides.size_bytes !== null ? t('overview.showcase.pdfSize', { size: formatFileSize(slides.size_bytes) }) : null}>
                        {slides.downloads.pdf && (
                            <a href={slides.downloads.pdf} download className={actionClasses}>
                                <DownloadIcon className="size-3.5" />
                                {t('overview.showcase.pdf')}
                            </a>
                        )}
                        {slides.downloads.pptx && (
                            <a href={slides.downloads.pptx} download className={actionClasses}>
                                <DownloadIcon className="size-3.5" />
                                {t('overview.showcase.pptx')}
                            </a>
                        )}
                    </Deliverable>
                )}

                {videos.length > 0 && (
                    <Deliverable name={t('overview.showcase.video')} detail={videos.length === 1 && videos[0].size_bytes !== null ? formatFileSize(videos[0].size_bytes) : null}>
                        {videos.map((video) => (
                            <a key={video.language} href={video.download ?? undefined} download className={actionClasses}>
                                <DownloadIcon className="size-3.5" />
                                <span>
                                    {t('overview.showcase.mp4')}
                                    {videos.length > 1 && <span lang={video.language}> · {video.language_label}</span>}
                                </span>
                                {videos.length > 1 && video.size_bytes !== null && <span className="font-normal text-ink-muted">{formatFileSize(video.size_bytes)}</span>}
                            </a>
                        ))}
                    </Deliverable>
                )}
            </ul>
        </section>
    );
}

/** One deliverable: its name (a file name is set in the code face), its size when known, and its actions. */
function Deliverable({ name, isFileName = false, detail, children }: { name: string; isFileName?: boolean; detail: string | null; children: ReactNode }) {
    return (
        <li className="flex flex-1 flex-col gap-3 p-4">
            <p className="flex items-baseline justify-between gap-3">
                <span className={`text-sm font-semibold text-ink ${isFileName ? 'font-mono' : ''}`}>{name}</span>
                {detail && <span className="text-xs text-ink-subtle tabular-nums">{detail}</span>}
            </p>
            <div className="flex flex-wrap gap-2">{children}</div>
        </li>
    );
}

/**
 * Copies the README's markdown: it is fetched from the download address when the button is pressed (it can be
 * long, so it is not sent with the page). The label says "Copied" for two seconds, also to screen readers.
 */
function CopyReadmeButton({ url }: { url: string }) {
    const { t } = useI18n();
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    async function copy() {
        try {
            const response = await fetch(url, { headers: { Accept: 'text/markdown' } });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            await navigator.clipboard.writeText(await response.text());
            setState('copied');
        } catch {
            setState('failed');
        }

        window.setTimeout(() => setState('idle'), 2000);
    }

    return (
        <button type="button" onClick={copy} className={actionClasses}>
            {state === 'copied' ? <CheckIcon className="size-3.5 text-success" /> : <CopyIcon className="size-3.5" />}
            <span aria-live="polite">
                {state === 'copied' ? t('overview.showcase.copied') : state === 'failed' ? t('overview.showcase.copyFailed') : t('overview.showcase.copy')}
            </span>
        </button>
    );
}
