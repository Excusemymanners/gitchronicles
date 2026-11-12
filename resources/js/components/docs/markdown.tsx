import { Link } from '@inertiajs/react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { translate } from '@/lib/i18n';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

// [&_code]:text-inherit: codul dintr-o legătură (de exemplu numele unei clase) are culoarea legăturii.
const linkClasses = `rounded-sm font-medium text-accent underline decoration-accent/40 underline-offset-2 break-words hover:text-accent-hover hover:decoration-accent-hover [&_code]:text-inherit ${focusRing}`;

/**
 * Cum arată fiecare element Markdown. Nu e instalat niciun plugin de tipografie, așa că stilul e dat aici,
 * element cu element. Legăturile:
 * - către o pagină a aplicației („/repositories/…”) sunt legături Inertia, fără reîncărcarea paginii;
 * - către alt site (GitHub) se deschid într-o filă nouă, fără acces la pagina noastră (rel="noopener noreferrer").
 * Imaginile nu se încarcă (ar veni de pe alte site-uri): apare doar o legătură spre ele.
 */
const components: Components = {
    h1: ({ node, ...props }) => <h1 className="mt-8 text-xl font-semibold sm:text-2xl tracking-tight break-words text-ink first:mt-0" {...props} />,
    h2: ({ node, ...props }) => (
        <h2 className="mt-10 border-b border-line pb-2 text-xl font-semibold tracking-tight text-ink first:mt-0" {...props} />
    ),
    h3: ({ node, ...props }) => <h3 className="mt-6 text-lg font-semibold text-ink first:mt-0" {...props} />,
    h4: ({ node, ...props }) => <h4 className="mt-6 font-semibold text-ink" {...props} />,
    p: ({ node, ...props }) => <p className="mt-4 leading-7 text-ink first:mt-0" {...props} />,
    a: ({ node, href = '', children, ...props }) => {
        if (href.startsWith('/') && !href.startsWith('//')) {
            return (
                <Link href={href} className={linkClasses}>
                    {children}
                </Link>
            );
        }

        if (href.startsWith('#')) {
            return (
                <a href={href} className={linkClasses} {...props}>
                    {children}
                </a>
            );
        }

        return (
            <a href={href} target="_blank" rel="noopener noreferrer" className={linkClasses} {...props}>
                {children}
                <span aria-hidden="true" className="ml-0.5 text-[0.75em] no-underline">
                    {'↗︎'}
                </span>
                <span className="sr-only"> {translate('common.opensInNewTab')}</span>
            </a>
        );
    },
    ul: ({ node, ...props }) => <ul className="mt-4 list-disc pl-6 marker:text-ink-subtle" {...props} />,
    ol: ({ node, ...props }) => <ol className="mt-4 list-decimal pl-6 marker:text-ink-subtle" {...props} />,
    li: ({ node, ...props }) => <li className="mt-1 leading-7 text-ink [&>ol]:mt-1 [&>ul]:mt-1" {...props} />,
    blockquote: ({ node, ...props }) => <blockquote className="mt-4 border-l-4 border-line-strong pl-4 text-ink-muted" {...props} />,
    // Codul din text; într-un bloc <pre>, regulile [&>code] de mai jos îi scot chenarul.
    code: ({ node, ...props }) => (
        <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-[0.875em] break-words text-ink" {...props} />
    ),
    pre: ({ node, ...props }) => (
        <pre
            className="relative mt-4 overflow-x-auto rounded-lg border border-line bg-surface p-4 font-mono text-sm leading-6 text-ink [&>code]:bg-transparent [&>code]:p-0 [&>code]:text-[1em] [&>code]:break-normal"
            {...props}
        />
    ),
    // „relative”: textele sr-only din tabel (poziționate absolut) rămân în caseta care se derulează,
    // altfel ar lărgi toată pagina pe telefon.
    table: ({ node, ...props }) => (
        <div className="relative mt-4 overflow-x-auto rounded-lg border border-line">
            <table className="w-full text-left text-sm" {...props} />
        </div>
    ),
    th: ({ node, ...props }) => <th className="border-b border-line bg-surface-2 px-3 py-2 font-medium whitespace-nowrap text-ink" {...props} />,
    td: ({ node, ...props }) => <td className="border-b border-line-subtle px-3 py-2 align-top text-ink-muted" {...props} />,
    hr: () => <hr className="my-8 border-line" />,
    img: ({ src, alt }) =>
        typeof src === 'string' ? (
            <a href={src} target="_blank" rel="noopener noreferrer" className={linkClasses}>
                {alt ? translate('docs.imageNamed', { alt }) : translate('docs.image')}<span className="sr-only"> {translate('common.opensInNewTab')}</span>
            </a>
        ) : null,
};

type Props = {
    /** Textul Markdown (GitHub-flavored: tabele, liste de sarcini, text tăiat). */
    children: string;
};

/**
 * Afișează Markdown-ul documentației generate. HTML-ul din text nu se afișează deloc (`skipHtml`), iar
 * react-markdown scoate singur adresele periculoase (de exemplu `javascript:`), așa că un text venit dintr-un
 * repository străin nu poate rula cod în pagină.
 */
export default function Markdown({ children }: Props) {
    return (
        <div className="min-w-0 text-base">
            <ReactMarkdown remarkPlugins={[remarkGfm]} skipHtml components={components}>
                {children}
            </ReactMarkdown>
        </div>
    );
}
