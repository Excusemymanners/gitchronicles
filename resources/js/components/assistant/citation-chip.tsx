import { useEffect, useId, useRef, useState } from 'react';
import type { MouseEvent, PointerEvent } from 'react';
import CitationPreview from '@/components/assistant/citation-preview';
import { useI18n } from '@/hooks/use-i18n';
import type { AssistantCitation } from '@/types';

type Props = {
    citation: AssistantCitation;
};

/** How long the card stays after the pointer leaves, so the pointer can travel from the marker to the card. */
const CLOSE_DELAY_MS = 150;

const chipClasses =
    'mx-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-sm bg-accent-soft px-1 align-text-top font-mono text-[0.75rem] text-accent hover:bg-accent hover:text-on-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent';

/** The lines of a fragment, short: "12–15" or "7". */
export function lineRange(citation: AssistantCitation): string {
    return citation.start_line === citation.end_line ? `${citation.start_line}` : `${citation.start_line}–${citation.end_line}`;
}

/**
 * The number of a citation ([1]) as a small marker. Hover or keyboard focus opens a preview card with the
 * cited lines (CitationPreview); Esc closes it. A click opens the same lines on GitHub.
 * On a touch screen the first tap only opens the card and the second tap follows the link.
 */
export default function CitationChip({ citation }: Props) {
    const { t } = useI18n();
    const anchorRef = useRef<HTMLAnchorElement & HTMLSpanElement>(null);
    const closeTimer = useRef<number | undefined>(undefined);
    const lastPointerType = useRef('mouse');
    const [isOpen, setIsOpen] = useState(false);
    const previewId = useId();
    const description = t('assistant.citation.description', { path: citation.path, range: lineRange(citation) });

    const open = () => {
        window.clearTimeout(closeTimer.current);
        setIsOpen(true);
    };
    const closeSoon = () => {
        window.clearTimeout(closeTimer.current);
        closeTimer.current = window.setTimeout(() => setIsOpen(false), CLOSE_DELAY_MS);
    };

    useEffect(() => () => window.clearTimeout(closeTimer.current), []);

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        // Esc closes the card first; the capture phase and stopPropagation keep a surrounding panel open.
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                setIsOpen(false);
            }
        };
        // A tap anywhere else closes it (touch has no "leave").
        const onPointerDown = (event: globalThis.PointerEvent) => {
            const target = event.target as Node;

            if (!anchorRef.current?.contains(target) && !document.getElementById(previewId)?.contains(target)) {
                setIsOpen(false);
            }
        };

        document.addEventListener('keydown', onKeyDown, true);
        document.addEventListener('pointerdown', onPointerDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown, true);
            document.removeEventListener('pointerdown', onPointerDown);
        };
    }, [isOpen, previewId]);

    const handlers = {
        onPointerDown: (event: PointerEvent) => {
            lastPointerType.current = event.pointerType;
        },
        onPointerEnter: (event: PointerEvent) => {
            if (event.pointerType === 'mouse') {
                open();
            }
        },
        onPointerLeave: (event: PointerEvent) => {
            if (event.pointerType === 'mouse') {
                closeSoon();
            }
        },
        // Only keyboard focus opens the card; a tap also focuses the link and must not skip the "first tap" step.
        onFocus: (event: React.FocusEvent<HTMLElement>) => {
            if (event.currentTarget.matches(':focus-visible')) {
                open();
            }
        },
        onBlur: closeSoon,
        onClick: (event: MouseEvent) => {
            if (lastPointerType.current === 'touch' && !isOpen) {
                event.preventDefault();
                open();
            }
        },
        'aria-describedby': isOpen ? previewId : undefined,
    };

    const preview = isOpen && <CitationPreview citation={citation} anchorRef={anchorRef} id={previewId} onPointerEnter={open} onPointerLeave={closeSoon} />;

    if (citation.url === null) {
        return (
            <>
                <span ref={anchorRef} tabIndex={0} className={chipClasses} {...handlers}>
                    {citation.index}
                    <span className="sr-only"> ({description})</span>
                </span>
                {preview}
            </>
        );
    }

    return (
        <>
            <a ref={anchorRef} href={citation.url} target="_blank" rel="noopener noreferrer" className={chipClasses} {...handlers}>
                {citation.index}
                <span className="sr-only"> {t('assistant.citation.sourceLink', { description })}</span>
            </a>
            {preview}
        </>
    );
}
