import { Link } from '@inertiajs/react';
import { useId } from 'react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    /** O legătură opțională în colțul cardului, de exemplu spre tab-ul „Istoric”. */
    link?: { href: string; label: string };
    children: ReactNode;
};

/**
 * Chenarul cardurilor compacte ale istoricului („Activitate”, „Zone fierbinți”): merg pe pagina Prezentare
 * și în panoul îngust cu elemente, așa că sunt mai mici decât SectionCard și se descurcă la orice lățime.
 */
export default function CompactCard({ title, link, children }: Props) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="rounded-lg border border-line bg-surface p-4 shadow-xs sm:p-5">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id={headingId} className="text-base font-semibold text-ink">
                    {title}
                </h2>
                {link && (
                    <Link
                        href={link.href}
                        className="rounded-sm text-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        {link.label} <span aria-hidden="true">→</span>
                    </Link>
                )}
            </div>
            <div className="mt-3">{children}</div>
        </section>
    );
}
