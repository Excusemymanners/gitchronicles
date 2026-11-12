import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '@/hooks/use-i18n';

/** The id of the empty element at the top of the workspace's right-hand column (layouts/workspace-layout.tsx). */
const ASIDE_SLOT_ID = 'workspace-aside-top';

export type PageHeading = {
    id: string;
    text: string;
    level: 2 | 3;
};

/**
 * Reads the h2 and h3 headings of a rendered article (they already carry ids, see markdown.tsx).
 * Reading the page itself, not the Markdown text, means the list can never disagree with the anchors.
 */
export function usePageHeadings(article: HTMLElement | null, markdown: string): PageHeading[] {
    const [headings, setHeadings] = useState<PageHeading[]>([]);

    useEffect(() => {
        if (!article) {
            return;
        }

        const found = Array.from(article.querySelectorAll<HTMLHeadingElement>('h2[id], h3[id]')).map((heading) => ({
            id: heading.id,
            text: heading.textContent ?? '',
            level: heading.tagName === 'H2' ? (2 as const) : (3 as const),
        }));

        setHeadings(found);
    }, [article, markdown]);

    return headings;
}

/** How far below the top of the scroll area a heading counts as "reached" (the headings use scroll-mt-24 = 96 px). */
const READING_LINE = 120;

/**
 * Scroll spy: the id of the section being read = the last heading that has passed the reading line near the top
 * of the scroll area (the first heading while none has). It is recomputed from the headings' real positions on
 * every scroll, at most once per frame. Positions are used instead of an IntersectionObserver because an observer
 * misses a heading that jumps over its band in one step (an anchor link, the End key).
 */
export function useActiveHeading(headings: PageHeading[], article: HTMLElement | null): string | null {
    const [activeId, setActiveId] = useState<string | null>(null);

    useEffect(() => {
        if (!article || headings.length === 0) {
            return;
        }

        const scrollArea = article.closest<HTMLElement>('[scroll-region]');
        const target: HTMLElement | Window = scrollArea ?? window;
        let frame = 0;

        const update = () => {
            frame = 0;

            const line = (scrollArea?.getBoundingClientRect().top ?? 0) + READING_LINE;
            let current = headings[0].id;

            headings.forEach((heading) => {
                const element = window.document.getElementById(heading.id);

                if (element && element.getBoundingClientRect().top <= line) {
                    current = heading.id;
                }
            });

            setActiveId(current);
        };

        const onScroll = () => {
            if (frame === 0) {
                frame = window.requestAnimationFrame(update);
            }
        };

        update();
        target.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            target.removeEventListener('scroll', onScroll);
            window.cancelAnimationFrame(frame);
        };
    }, [headings, article]);

    return activeId;
}

/** Whether the workspace currently shows its right-hand column (it is hidden on narrower screens). */
function useAsideSlot(): HTMLElement | null {
    const [slot, setSlot] = useState<HTMLElement | null>(null);

    useEffect(() => {
        const look = () => setSlot(window.document.getElementById(ASIDE_SLOT_ID));

        look();
        window.addEventListener('resize', look);

        return () => window.removeEventListener('resize', look);
    }, []);

    return slot;
}

function HeadingList({ headings, activeId }: { headings: PageHeading[]; activeId: string | null }) {
    return (
        <ul className="border-l-2 border-line">
            {headings.map((heading) => {
                const isActive = heading.id === activeId;

                return (
                    <li key={heading.id}>
                        <a
                            href={`#${heading.id}`}
                            aria-current={isActive ? 'location' : undefined}
                            className={`-ml-0.5 block border-l-2 py-1 text-[0.8125rem] leading-5 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${
                                heading.level === 3 ? 'pl-6' : 'pl-3'
                            } ${isActive ? 'border-accent text-accent hover:text-accent' : 'border-transparent text-ink-muted'}`}
                        >
                            {heading.text}
                        </a>
                    </li>
                );
            })}
        </ul>
    );
}

/**
 * "On this page": a collapsible list at the top of the article on narrow screens and, when the workspace has its
 * right-hand column, a sticky list at the top of that column (through a portal into the slot the layout provides).
 */
export default function OnThisPage({ headings, activeId }: { headings: PageHeading[]; activeId: string | null }) {
    const { t } = useI18n();
    const slot = useAsideSlot();

    if (headings.length < 2) {
        return null;
    }

    if (slot) {
        return createPortal(
            <nav aria-label={t('docs.onThisPage')} className="max-h-[50dvh] overflow-y-auto border-b border-line bg-surface px-5 pt-5 pb-4">
                <p className="mb-2 text-[0.8125rem] font-medium text-ink-subtle">{t('docs.onThisPage')}</p>
                <HeadingList headings={headings} activeId={activeId} />
            </nav>,
            slot,
        );
    }

    return (
        <details className="mb-6 rounded-lg border border-line bg-surface px-4 py-3">
            <summary className="cursor-pointer text-sm font-medium text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                {t('docs.onThisPage')}
            </summary>
            <nav aria-label={t('docs.onThisPage')} className="mt-3">
                <HeadingList headings={headings} activeId={activeId} />
            </nav>
        </details>
    );
}
