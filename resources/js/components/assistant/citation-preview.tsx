import { useLayoutEffect, useRef, useState } from 'react';
import type { CSSProperties, RefObject } from 'react';
import { createPortal } from 'react-dom';
import { lineRange } from '@/components/assistant/citation-chip';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { AssistantCitation } from '@/types';

type Props = {
    citation: AssistantCitation;
    /** The element the card hangs from (the [n] marker or a source chip). */
    anchorRef: RefObject<HTMLElement | null>;
    id: string;
    /** Keep the card open while the pointer is on it. */
    onPointerEnter: () => void;
    onPointerLeave: () => void;
};

const GAP = 6;
const EDGE = 8;

/**
 * The card that shows the cited lines: path and line range, the code with its real line numbers
 * and a link to the same lines on GitHub. It is drawn in a portal with `position: fixed`, so no
 * scrolling column can clip it, and it is moved to stay inside the viewport (below the marker, or
 * above it when there is no room, and never past the left or right edge).
 */
export default function CitationPreview({ citation, anchorRef, id, onPointerEnter, onPointerLeave }: Props) {
    const { t } = useI18n();
    const cardRef = useRef<HTMLDivElement>(null);
    const [position, setPosition] = useState<CSSProperties | null>(null);
    const lines = citation.snippet.split('\n');
    const hiddenLines = Math.max(0, citation.end_line - citation.start_line + 1 - lines.length);

    useLayoutEffect(() => {
        const place = () => {
            const anchor = anchorRef.current?.getBoundingClientRect();
            const card = cardRef.current;

            if (!anchor || !card) {
                return;
            }

            const left = Math.min(Math.max(anchor.left, EDGE), window.innerWidth - card.offsetWidth - EDGE);
            const fitsBelow = anchor.bottom + GAP + card.offsetHeight <= window.innerHeight - EDGE;
            const top = fitsBelow ? anchor.bottom + GAP : Math.max(EDGE, anchor.top - GAP - card.offsetHeight);

            setPosition({ left: Math.max(EDGE, left), top });
        };

        place();
        window.addEventListener('resize', place);
        window.addEventListener('scroll', place, true);

        return () => {
            window.removeEventListener('resize', place);
            window.removeEventListener('scroll', place, true);
        };
    }, [anchorRef]);

    // Inside an open dialog the card must live in the dialog, or the dialog's top layer would cover it.
    const host = anchorRef.current?.closest('dialog, [role="dialog"]') ?? document.body;

    return createPortal(
        <div
            ref={cardRef}
            id={id}
            role="tooltip"
            onPointerEnter={onPointerEnter}
            onPointerLeave={onPointerLeave}
            style={{ position: 'fixed', ...(position ?? { left: 0, top: 0, visibility: 'hidden' }) }}
            className="z-[200] w-[min(32rem,90vw)] animate-rise overflow-hidden rounded-lg border border-line bg-surface-raised text-left shadow-float"
        >
            <div className="flex flex-col gap-0.5 border-b border-line-subtle px-3.5 py-2.5">
                <p className="break-all font-mono text-[0.8125rem] font-medium text-ink">{citation.path}</p>
                <p className="text-[0.8125rem] text-ink-subtle">
                    {t('assistant.thread.lines', { range: lineRange(citation) })}
                    {citation.symbol_name && ` · ${citation.symbol_name}`}
                </p>
            </div>

            {citation.snippet !== '' && (
                <div className="max-h-72 overflow-auto bg-canvas py-2 font-mono text-[0.8125rem] leading-relaxed text-ink" aria-label={t('assistant.citation.preview', { path: citation.path })}>
                    {lines.map((line, index) => (
                        <div key={index} className="flex">
                            <span aria-hidden="true" className="w-12 shrink-0 pr-3 text-right text-ink-subtle tabular-nums select-none">
                                {citation.start_line + index}
                            </span>
                            <span className="pr-3 whitespace-pre">{line}</span>
                        </div>
                    ))}
                    {hiddenLines > 0 && (
                        <p className="mt-1 pl-12 text-ink-subtle">{hiddenLines === 1 ? t('assistant.citation.moreLine') : t('assistant.citation.moreLines', { count: hiddenLines })}</p>
                    )}
                </div>
            )}

            {citation.url && (
                <div className="border-t border-line-subtle px-3.5 py-2">
                    <a
                        href={citation.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 rounded-sm text-[0.8125rem] font-medium text-accent hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        {t('assistant.citation.openOnGitHub')}
                        <ExternalLinkIcon className="size-3.5" />
                        <span className="sr-only"> {t('common.opensInNewTab')}</span>
                    </a>
                </div>
            )}
        </div>,
        host,
    );
}
