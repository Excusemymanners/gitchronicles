import { useI18n } from '@/hooks/use-i18n';
import { formatTimecode } from '@/lib/format';
import type { VideoScene } from '@/types/media';

type Props = {
    scenes: VideoScene[];
    /** Mută video-ul la începutul scenei și îl pornește. */
    onSeek: (milliseconds: number) => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Scenele video-ului, în ordine, fiecare cu sursa ei: de unde vine fiecare propoziție. Așa se poate
 * verifica ușor că video-ul spune doar lucruri reale despre repository: textul de pe ecran, ce spune
 * vocea (când diferă) și citatul exact din README, din documentația generată sau de pe GitHub.
 * Momentul scenei e un buton care mută video-ul acolo.
 */
export default function SceneList({ scenes, onSeek }: Props) {
    const { t } = useI18n();

    return (
        <ol className="divide-y divide-line rounded-lg border border-line bg-surface">
            {scenes.map((scene, index) => {
                const shown = [scene.title, scene.text].filter(Boolean).join(' — ');

                return (
                    <li key={`${scene.key}-${index}`} className="flex gap-3 p-4 sm:gap-4">
                        <button
                            type="button"
                            onClick={() => onSeek(scene.start_ms)}
                            className={`h-fit shrink-0 cursor-pointer rounded-md bg-surface-2 px-2 py-1 font-mono text-xs text-accent tabular-nums hover:text-accent-hover ${focusRing}`}
                        >
                            <span className="sr-only">{t('media.scenes.playFrom')}</span>
                            {formatTimecode(scene.start_ms)}
                        </button>

                        {/* wrap-anywhere: un citat poate fi o adresă lungă, fără spații, care altfel ar ieși din card pe telefon. */}
                        <div className="min-w-0 flex-1 wrap-anywhere">
                            <p className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">{scene.label}</p>
                            {shown !== '' && <p className="mt-1 text-sm font-medium text-ink">{shown}</p>}
                            {scene.command && (
                                <p className="mt-2">
                                    <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-[0.8125rem] text-ink">{scene.command}</code>
                                </p>
                            )}
                            {scene.narration && scene.narration !== scene.text && (
                                <p className="mt-2 text-sm text-ink-muted">
                                    <span className="text-ink-subtle">{t('media.scenes.narration')}</span>
                                    {scene.narration}
                                </p>
                            )}

                            {scene.source ? (
                                <figure className="mt-3 border-l-2 border-line-strong pl-3">
                                    <blockquote className="text-sm text-ink-muted">„{scene.source.quote}”</blockquote>
                                    <figcaption className="mt-1 text-xs text-ink-subtle">
                                        {t('media.scenes.source')}
                                        {scene.source.url ? (
                                            <a
                                                href={scene.source.url}
                                                target={scene.source.url.startsWith('http') ? '_blank' : undefined}
                                                rel="noopener noreferrer"
                                                className={`rounded-sm font-medium text-accent hover:text-accent-hover hover:underline ${focusRing}`}
                                            >
                                                {scene.source.label}
                                                {scene.source.url.startsWith('http') && (
                                                    <>
                                                        <span aria-hidden="true"> {'↗︎'}</span>
                                                        <span className="sr-only"> {t('common.opensInNewTab')}</span>
                                                    </>
                                                )}
                                            </a>
                                        ) : (
                                            <span className="font-medium text-ink-muted">{scene.source.label}</span>
                                        )}
                                    </figcaption>
                                </figure>
                            ) : (
                                <p className="mt-3 text-xs text-ink-subtle">{t('media.scenes.template')}</p>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
