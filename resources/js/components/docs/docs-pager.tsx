import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon } from '@/components/docs/docs-icons';
import { useI18n } from '@/hooks/use-i18n';
import type { DocumentLink } from '@/types/docs';

type Props = {
    /** Capitolele documentației, în ordinea cuprinsului (DocumentNavigation). */
    items: DocumentLink[];
    /** Pagina deschisă. */
    currentSlug: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * "Previous chapter" and "Next chapter" at the end of a page, as on a documentation site: the whole documentation
 * can be read in order without going back to the table of contents. It is a segment of the rail, not two boxes.
 * The first page has no previous chapter, the last one no next.
 */
export default function DocsPager({ items, currentSlug }: Props) {
    const { t } = useI18n();
    const index = items.findIndex((item) => item.slug === currentSlug);

    if (index === -1 || items.length < 2) {
        return null;
    }

    const previous = items[index - 1] ?? null;
    const next = items[index + 1] ?? null;

    return (
        <nav aria-label={t('docs.pagesNav')} className="mt-16 grid grid-cols-2 gap-4 border-t border-line pt-6">
            {previous ? <PagerLink item={previous} direction="previous" /> : <span aria-hidden="true" />}
            {next && <PagerLink item={next} direction="next" />}
        </nav>
    );
}

function PagerLink({ item, direction }: { item: DocumentLink; direction: 'previous' | 'next' }) {
    const { t } = useI18n();
    const isNext = direction === 'next';
    const Chevron = isNext ? ChevronRightIcon : ChevronLeftIcon;

    return (
        <Link
            href={item.href}
            prefetch
            className={`group flex min-w-0 flex-col gap-1 rounded-sm ${isNext ? 'col-start-2 items-end text-right' : ''} ${focusRing}`}
        >
            <span className="flex items-center gap-1 text-[0.8125rem] text-ink-subtle">
                {!isNext && <Chevron />}
                {isNext ? t('docs.nextPage') : t('docs.previousPage')}
                {isNext && <Chevron />}
            </span>
            <span className="max-w-full truncate font-medium text-ink group-hover:text-accent">{item.title}</span>
        </Link>
    );
}
