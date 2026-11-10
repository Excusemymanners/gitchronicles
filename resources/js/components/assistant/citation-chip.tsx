import type { AssistantCitation } from '@/types';

type Props = {
    citation: AssistantCitation;
};

const chipClasses =
    'mx-0.5 inline-flex h-5 min-w-5 items-center justify-center rounded-md bg-accent-soft px-1 align-text-bottom text-xs font-semibold text-accent tabular-nums ring-1 ring-accent/30 ring-inset';

/** Liniile unui fragment, pe scurt: „12–15” sau „7”. */
export function lineRange(citation: AssistantCitation): string {
    return citation.start_line === citation.end_line ? `${citation.start_line}` : `${citation.start_line}–${citation.end_line}`;
}

/**
 * Numărul unei citări din răspuns ([1]), ca un butonaș care deschide liniile fragmentului pe GitHub.
 * Fișierul și liniile apar la hover (title) și sunt citite de cititoarele de ecran.
 */
export default function CitationChip({ citation }: Props) {
    const description = `${citation.path}, liniile ${lineRange(citation)}`;

    if (citation.url === null) {
        return (
            <span className={chipClasses} title={description}>
                {citation.index}
                <span className="sr-only"> ({description})</span>
            </span>
        );
    }

    return (
        <a
            href={citation.url}
            target="_blank"
            rel="noopener noreferrer"
            title={description}
            className={`${chipClasses} hover:bg-accent/20 hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-accent`}
        >
            {citation.index}
            <span className="sr-only"> (sursa: {description}, pe GitHub, se deschide într-o filă nouă)</span>
        </a>
    );
}
