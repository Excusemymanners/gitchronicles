import { useId } from 'react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    /** An optional link or action on the right of the title, for example "All the documentation". */
    aside?: ReactNode;
    children: ReactNode;
};

/**
 * A titled section of a repository page, drawn without a box: a hairline above and a display-face title, so the
 * Overview reads as one page rather than a stack of cards. The title also names the section for screen readers.
 */
export default function SectionCard({ title, aside, children }: Props) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="border-t border-line pt-8">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 id={headingId} className="font-display text-heading font-semibold text-ink">
                    {title}
                </h2>
                {aside}
            </div>
            <div className="mt-4">{children}</div>
        </section>
    );
}
