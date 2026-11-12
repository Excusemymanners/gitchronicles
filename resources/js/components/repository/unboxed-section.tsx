import type { ReactNode } from 'react';

/**
 * The History tab's cards (ActivityCard, HotspotsCard) come in a box of their own. On the Overview they must read
 * as sections of one page, so this wrapper takes their frame away — no border, background, padding or shadow —
 * draws the Overview's hairline instead, and sets their title in the section heading style.
 */
export default function UnboxedSection({ children }: { children: ReactNode }) {
    return (
        <div className="min-w-0 border-t border-line pt-8 [&>section]:rounded-none [&>section]:border-0 [&>section]:bg-transparent [&>section]:p-0 [&>section]:shadow-none sm:[&>section]:p-0 [&>section_h2]:font-display [&>section_h2]:text-heading">
            {children}
        </div>
    );
}
