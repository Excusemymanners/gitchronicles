import { Link } from '@inertiajs/react';
import type { DocumentLink } from '@/types/docs';

type Props = {
    /** Capitolele documentației, în ordinea cuprinsului (DocumentNavigation). */
    items: DocumentLink[];
    /** Pagina deschisă. */
    currentSlug: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * „Pagina anterioară” și „Pagina următoare” la finalul unei pagini, ca pe un site de documentație:
 * se poate citi toată documentația pe rând, fără să cauți următorul capitol în cuprins.
 * Prima pagină nu are „anterioară”, ultima nu are „următoare”.
 */
export default function DocsPager({ items, currentSlug }: Props) {
    const index = items.findIndex((item) => item.slug === currentSlug);

    if (index === -1 || items.length < 2) {
        return null;
    }

    const previous = items[index - 1] ?? null;
    const next = items[index + 1] ?? null;

    return (
        <nav aria-label="Paginile documentației" className="mt-12 grid gap-3 border-t border-line pt-6 sm:grid-cols-2">
            {previous ? <PagerLink item={previous} direction="previous" /> : <span aria-hidden="true" className="max-sm:hidden" />}
            {next && <PagerLink item={next} direction="next" />}
        </nav>
    );
}

function PagerLink({ item, direction }: { item: DocumentLink; direction: 'previous' | 'next' }) {
    const isNext = direction === 'next';

    return (
        <Link
            href={item.href}
            className={`group flex flex-col gap-1 rounded-xl border border-line px-4 py-3 hover:border-line-strong hover:bg-surface ${isNext ? 'sm:items-end sm:text-right' : ''} ${focusRing}`}
        >
            <span className="text-xs text-ink-subtle">{isNext ? 'Pagina următoare →' : '← Pagina anterioară'}</span>
            <span className="max-w-full truncate font-medium text-ink group-hover:text-accent">{item.title}</span>
        </Link>
    );
}
