import type { ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import CitationChip from '@/components/assistant/citation-chip';
import { translate } from '@/lib/i18n';
import type { AssistantCitation } from '@/types';

type Props = {
    markdown: string;
    citations: AssistantCitation[];
};

/** Legăturile interne prin care marcăm citările în Markdown, înainte de desenare. */
const CITATION_HREF_PREFIX = '#citation-';

/**
 * Textul unui răspuns, desenat din Markdown (cu tabele și liste GitHub, prin remark-gfm).
 * HTML-ul scris în răspuns nu se desenează niciodată (skipHtml), iar react-markdown curăță
 * singur legăturile periculoase (de exemplu „javascript:”).
 *
 * Citările [1], [2] devin butonașe numerotate care deschid liniile pe GitHub; un număr fără
 * fragment rămâne text, iar codul (blocuri și `inline`) nu se atinge.
 */
export default function AnswerMarkdown({ markdown, citations }: Props) {
    const citationsByIndex = new Map(citations.map((citation) => [citation.index, citation]));

    const components: Components = {
        a: ({ href, children }) => {
            if (href?.startsWith(CITATION_HREF_PREFIX)) {
                const citation = citationsByIndex.get(Number(href.slice(CITATION_HREF_PREFIX.length)));

                return citation ? <CitationChip citation={citation} /> : <>{children}</>;
            }

            return (
                <a
                    href={href}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="rounded-sm font-medium text-accent underline decoration-accent/40 underline-offset-2 hover:text-accent-hover hover:decoration-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    {children}
                    <span className="sr-only"> {translate('common.opensInNewTab')}</span>
                </a>
            );
        },
        // An image written by the model is never loaded (its address could carry the visitor's words to another
        // server): it becomes a link, like in the documentation pages.
        img: ({ src, alt }) =>
            typeof src === 'string' ? (
                <a
                    href={src}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="rounded-sm font-medium text-accent underline decoration-accent/40 underline-offset-2 hover:text-accent-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    {alt ? translate('docs.imageNamed', { alt }) : translate('docs.image')}
                    <span className="sr-only"> {translate('common.opensInNewTab')}</span>
                </a>
            ) : null,
        p: ({ children }) => <p className="leading-7 text-ink">{children}</p>,
        ul: ({ children }) => <ul className="list-disc space-y-2 pl-5 marker:text-ink-subtle">{children}</ul>,
        ol: ({ children }) => <ol className="list-decimal space-y-2 pl-5 marker:text-ink-subtle">{children}</ol>,
        li: ({ children }) => <li className="leading-7 text-ink [&>ol]:mt-2 [&>ul]:mt-2">{children}</li>,
        h1: ({ children }) => <Heading>{children}</Heading>,
        h2: ({ children }) => <Heading>{children}</Heading>,
        h3: ({ children }) => <Heading>{children}</Heading>,
        h4: ({ children }) => <Heading>{children}</Heading>,
        blockquote: ({ children }) => <blockquote className="border-l-2 border-accent pl-4 text-ink-muted">{children}</blockquote>,
        pre: ({ children }) => (
            <pre className="overflow-x-auto rounded-lg border border-line bg-canvas p-3 font-mono text-[0.8125rem] leading-relaxed text-ink">{children}</pre>
        ),
        code: ({ className, children }) =>
            className?.startsWith('language-') || String(children).includes('\n') ? (
                <code className={className}>{children}</code>
            ) : (
                <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono text-[0.8125em] break-words text-ink">{children}</code>
            ),
        table: ({ children }) => (
            <div className="overflow-x-auto rounded-lg border border-line">
                <table className="w-full text-left text-sm">{children}</table>
            </div>
        ),
        th: ({ children }) => <th className="bg-surface-2 px-3 py-2 text-left text-[0.8125rem] font-medium whitespace-nowrap text-ink-muted">{children}</th>,
        td: ({ children }) => <td className="border-t border-line-subtle px-3 py-2 align-top text-ink-muted first:text-ink">{children}</td>,
    };

    return (
        <div className="flex min-w-0 flex-col gap-4 text-[0.9375rem] text-ink">
            <ReactMarkdown remarkPlugins={[remarkGfm]} skipHtml components={components}>
                {linkCitations(markdown, new Set(citationsByIndex.keys()))}
            </ReactMarkdown>
        </div>
    );
}

/** Titlurile din răspuns sunt mici: un răspuns e un mesaj, nu o pagină. */
function Heading({ children }: { children?: ReactNode }) {
    return <p className="text-base font-semibold text-ink">{children}</p>;
}

/**
 * Transformă citările [1], [1, 3] din afara codului în legături Markdown interne ([\[1\]](#citation-1)),
 * pe care componenta `a` le desenează ca butonașe. Codul rămâne neatins: rândurile dintre ``` sau ~~~
 * și bucățile dintre `...` de pe un rând.
 */
function linkCitations(markdown: string, knownIndexes: Set<number>): string {
    let openFence: string | null = null;

    return markdown
        .split('\n')
        .map((line) => {
            const fence = line.match(/^\s*(`{3,}|~{3,})/)?.[1];

            if (fence !== undefined) {
                if (openFence === null) {
                    openFence = fence;
                } else if (fence[0] === openFence[0] && fence.length >= openFence.length) {
                    openFence = null;
                }

                return line;
            }

            if (openFence !== null) {
                return line;
            }

            return line
                .split(/(`[^`]*`)/)
                .map((part) => (part.startsWith('`') ? part : part.replace(/\[(\d+(?:\s*,\s*\d+)*)\](?!\()/g, (text, numbers: string) => citationLinks(text, numbers, knownIndexes))))
                .join('');
        })
        .join('\n');
}

/** „[1, 3]” → două legături de citare; un număr fără fragment rămâne text, între paranteze. */
function citationLinks(text: string, numbers: string, knownIndexes: Set<number>): string {
    const indexes = numbers.split(',').map((number) => Number(number.trim()));

    if (!indexes.every((index) => knownIndexes.has(index))) {
        return text;
    }

    return indexes.map((index) => `[\\[${index}\\]](${CITATION_HREF_PREFIX}${index})`).join('');
}
