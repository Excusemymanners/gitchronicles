import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import PictureDialog from '@/components/docs/picture-dialog';
import Markdown from '@/components/docs/markdown';
import { ArrowRightIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatDay, formatNumber, formatPercent, shortSha } from '@/lib/format';
import type { DocumentUpdate, DocumentVisuals, ReadmePicture } from '@/types/docs';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** A picture with its caption. Every picture of a page sits in one of these, so the caption style is the same everywhere. */
function Figure({ children, caption, className = '' }: { children: ReactNode; caption: ReactNode; className?: string }) {
    return (
        <figure className={`mt-8 ${className}`}>
            {children}
            <figcaption className="mt-2 text-[0.8125rem] text-ink-subtle">{caption}</figcaption>
        </figure>
    );
}

/**
 * A picture that opens full size in a dialog. The frame has a fixed aspect ratio, so the page does not move when the
 * picture arrives; the picture loads when it is near the screen.
 */
function ZoomablePicture({
    url,
    alt,
    caption,
    ratio,
    fit,
    label,
}: {
    url: string;
    alt: string;
    caption: string;
    ratio: string;
    fit: 'cover' | 'contain';
    label: string;
}) {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <>
            <button
                type="button"
                onClick={() => setIsOpen(true)}
                aria-label={label}
                style={{ aspectRatio: ratio }}
                className={`pressable block w-full cursor-zoom-in overflow-hidden rounded-lg border border-line bg-surface-2 ${focusRing}`}
            >
                <img
                    src={url}
                    alt={alt}
                    loading="lazy"
                    decoding="async"
                    className={`size-full ${fit === 'cover' ? 'object-cover object-top' : 'object-contain'}`}
                />
            </button>
            {isOpen && <PictureDialog url={url} alt={alt} caption={caption} onClose={() => setIsOpen(false)} />}
        </>
    );
}

/** The page's opening picture: the repository's own GitHub page, as captured for its video. Shown only when a capture exists. */
export function OpeningPicture({ visuals, repositoryFullName }: { visuals: DocumentVisuals; repositoryFullName: string }) {
    const { t } = useI18n();
    const capture = visuals.capture;

    if (capture === null) {
        return null;
    }

    const caption = t('docs.visuals.captureCaption', {
        date: capture.captured_at ? formatDay(capture.captured_at.slice(0, 10)) : '',
    });
    const alt = t('docs.visuals.captureAlt', {
        repository: repositoryFullName,
    });

    return (
        <Figure caption={caption}>
            <ZoomablePicture url={capture.url} alt={alt} caption={caption} ratio="16 / 7" fit="cover" label={t('docs.visuals.fullSize', { alt })} />
        </Figure>
    );
}

/** One shade per language, from the strongest to the faintest accent: tokens only. */
const SHADES = ['bg-accent', 'bg-accent/85', 'bg-accent/70', 'bg-accent/55', 'bg-accent/40', 'bg-accent/30'];

/** The languages bar and the key numbers as one compact strip, from the analysis of the code. */
export function FactsStrip({ visuals }: { visuals: DocumentVisuals }) {
    const { t, plural } = useI18n();
    const facts = visuals.facts;

    if (facts === null) {
        return null;
    }

    const numbers = [plural('files', facts.files), plural('lines', facts.lines), plural('modules', facts.modules), plural('commits', facts.commits)];

    return (
        <Figure
            caption={t('docs.visuals.factsCaption', {
                sha: visuals.commit ? shortSha(visuals.commit) : '',
            })}
        >
            <div role="group" aria-label={t('docs.visuals.factsLabel')} className="rounded-lg border border-line bg-surface px-4 py-4">
                <ul className="flex flex-wrap gap-x-8 gap-y-1 text-sm text-ink">
                    {numbers.map((number) => (
                        <li key={number} className="font-medium">
                            {number}
                        </li>
                    ))}
                </ul>
                {facts.languages.length > 0 && (
                    <>
                        <div
                            role="img"
                            aria-label={t('docs.visuals.languagesLabel')}
                            className="mt-3 flex h-2.5 overflow-hidden rounded-full bg-surface-2"
                        >
                            {facts.languages.map((language, index) => (
                                <span
                                    key={language.name}
                                    style={{
                                        width: `${Math.max(language.share * 100, 1)}%`,
                                    }}
                                    className={SHADES[index % SHADES.length]}
                                />
                            ))}
                        </div>
                        <ul className="mt-3 flex flex-wrap gap-x-5 gap-y-1 text-[0.8125rem] text-ink-muted">
                            {facts.languages.map((language, index) => (
                                <li key={language.name} className="flex items-center gap-1.5">
                                    <span aria-hidden="true" className={`size-2.5 rounded-sm ${SHADES[index % SHADES.length]}`} />
                                    {language.name} <span className="text-ink-subtle">{formatPercent(language.share)}</span>
                                </li>
                            ))}
                        </ul>
                    </>
                )}
            </div>
        </Figure>
    );
}

/**
 * The architecture diagram, drawn from the real import graph, shown where the text talks about structure. It uses the
 * existing Mermaid preview (loaded only when it is near the screen); a click opens the Diagrams page with all views.
 */
export function ArchitectureFigure({ visuals }: { visuals: DocumentVisuals }) {
    const { t } = useI18n();
    const architecture = visuals.architecture;

    if (architecture === null) {
        return null;
    }

    return (
        <Figure
            caption={
                <>
                    {t('docs.visuals.architectureCaption', {
                        sha: visuals.commit ? shortSha(visuals.commit) : '',
                    })}{' '}
                    <Link
                        href={architecture.href}
                        prefetch
                        className={`rounded-sm font-medium text-accent hover:text-accent-hover hover:underline ${focusRing}`}
                    >
                        {t('docs.visuals.openDiagrams')}
                    </Link>
                </>
            }
        >
            <div className="h-80 overflow-hidden rounded-lg border border-line bg-surface p-3 sm:h-[26rem]">
                <MermaidDiagram code={architecture.mermaid} title={architecture.title} compact />
            </div>
        </Figure>
    );
}

/** "From the repository's README": its own pictures with their captions; a click shows one at full size. */
export function ReadmeGallery({ visuals }: { visuals: DocumentVisuals }) {
    const { t } = useI18n();

    const pictures = visuals.pictures.filter((picture) => visuals.gallery.includes(picture.id));

    if (pictures.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="readme-pictures" className="mt-12">
            <h2 id="readme-pictures" className="font-display text-heading text-ink">
                {t('docs.visuals.galleryTitle')}
            </h2>
            <ul className="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                {pictures.map((picture, index) => (
                    <li key={picture.id}>
                        <GalleryPicture picture={picture} number={index + 1} />
                    </li>
                ))}
            </ul>
        </section>
    );
}

function GalleryPicture({ picture, number }: { picture: ReadmePicture; number: number }) {
    const { t } = useI18n();
    const alt = picture.alt !== '' ? picture.alt : t('docs.visuals.pictureAlt', { number });
    const caption = picture.section ? t('docs.visuals.fromReadmeSection', { section: picture.section }) : t('docs.visuals.fromReadme');

    return (
        <Figure
            caption={
                <>
                    {picture.alt !== '' && <span className="text-ink-muted">{picture.alt} · </span>}
                    {caption}
                </>
            }
            className="!mt-4"
        >
            {/* One fixed frame for every picture: a tall one shrinks to fit, so nothing moves when it loads. */}
            <ZoomablePicture
                url={picture.url}
                alt={alt}
                caption={caption}
                ratio="16 / 10"
                fit="contain"
                label={t('docs.visuals.fullSize', { alt })}
            />
        </Figure>
    );
}

/** "How a change travels": the latest updates as a row of steps, commits then pages rewritten, from the processing runs. */
export function UpdateTimeline({ visuals }: { visuals: DocumentVisuals }) {
    const { t } = useI18n();

    if (visuals.updates.length === 0) {
        return null;
    }

    return (
        <section aria-labelledby="update-timeline" className="mt-12">
            <h2 id="update-timeline" className="font-display text-heading text-ink">
                {t('docs.visuals.updatesTitle')}
            </h2>
            <ol className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {visuals.updates.map((update) => (
                    <UpdateStep key={`${update.from_sha}-${update.to_sha}`} update={update} />
                ))}
            </ol>
            <p className="mt-3 text-[0.8125rem] text-ink-subtle">{t('docs.visuals.updatesCaption')}</p>
        </section>
    );
}

function UpdateStep({ update }: { update: DocumentUpdate }) {
    const { t, plural } = useI18n();

    return (
        <li className="rounded-lg border border-line bg-surface p-4">
            <p className="flex items-center gap-2 font-mono text-[0.8125rem] text-ink">
                {update.from_sha ? shortSha(update.from_sha) : ''}
                <ArrowRightIcon className="size-3.5 text-ink-subtle" />
                {update.to_sha ? shortSha(update.to_sha) : ''}
            </p>
            {update.finished_at && <p className="mt-0.5 text-[0.8125rem] text-ink-subtle">{formatDay(update.finished_at.slice(0, 10))}</p>}
            <p className="mt-3 text-sm text-ink-muted">{plural('filesChanged', update.files_changed)}</p>
            <p className="flex items-center gap-1.5 text-sm text-ink-muted">
                <ArrowRightIcon className="size-3.5 text-ink-subtle" />
                <span className="font-medium text-ink">{plural('pagesRewritten', update.documents_regenerated)}</span>
                {update.documents_kept > 0 && (
                    <span className="text-ink-subtle">
                        ·{' '}
                        {t('docs.visuals.updateKept', {
                            count: formatNumber(update.documents_kept),
                        })}
                    </span>
                )}
            </p>
        </li>
    );
}

/**
 * "From the project's own README": the README's descriptive sections under their own headings, with their pictures,
 * rendered through the safe Markdown component. On the overview it is shorter and collapsed (a native <details>) with a
 * link to the generated README for the rest; on the README it is open.
 */
export function ProjectDescription({ visuals, collapsed }: { visuals: DocumentVisuals; collapsed: boolean }) {
    const { t } = useI18n();
    const description = visuals.description;

    if (description === null) {
        return null;
    }

    const caption = t('docs.visuals.descriptionCaption', { sha: visuals.commit ? shortSha(visuals.commit) : '' });
    const sections = (
        <div className="mt-4 space-y-8">
            {description.sections.map((section, index) => (
                <div key={`${index}-${section.heading}`}>
                    <h3 className="text-lg font-semibold tracking-tight text-ink">{section.heading ?? t('docs.visuals.introHeading')}</h3>
                    <Markdown>{section.markdown}</Markdown>
                </div>
            ))}
        </div>
    );

    if (!collapsed) {
        return (
            <section aria-labelledby="own-description" className="mt-12">
                <h2 id="own-description" className="font-display text-heading text-ink">
                    {t('docs.visuals.galleryTitle')}
                </h2>
                <p className="mt-1 text-[0.8125rem] text-ink-subtle">{caption}</p>
                {sections}
            </section>
        );
    }

    return (
        <details className="mt-12 rounded-lg border border-line bg-surface px-4 py-3">
            <summary className={`cursor-pointer rounded-sm font-medium text-ink ${focusRing}`}>{t('docs.visuals.readDescription')}</summary>
            <p className="mt-2 text-[0.8125rem] text-ink-subtle">{caption}</p>
            {sections}
            {description.total > description.sections.length && (
                <p className="mt-6 text-sm">
                    <Link
                        href={description.readme_href}
                        prefetch
                        className={`rounded-sm font-medium text-accent hover:text-accent-hover hover:underline ${focusRing}`}
                    >
                        {t('docs.visuals.moreInReadme')}
                    </Link>
                </p>
            )}
        </details>
    );
}
