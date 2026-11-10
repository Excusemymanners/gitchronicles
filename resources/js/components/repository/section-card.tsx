import { useId } from 'react';
import type { ReactNode } from 'react';

type Props = {
    title: string;
    children: ReactNode;
};

/**
 * Chenarul alb cu titlu folosit de secțiunile paginilor unui repository,
 * ca toate să arate la fel. Titlul dă și numele secțiunii pentru cititoarele de ecran.
 */
export default function SectionCard({ title, children }: Props) {
    const headingId = useId();

    return (
        <section aria-labelledby={headingId} className="rounded-lg border border-gray-200 bg-white p-6 shadow-xs">
            <h2 id={headingId} className="text-base font-semibold text-gray-900">
                {title}
            </h2>
            <div className="mt-4">{children}</div>
        </section>
    );
}
