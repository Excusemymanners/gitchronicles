import { Link } from '@inertiajs/react';
import { isValidElement } from 'react';
import type { ComponentProps, ReactNode } from 'react';
import ReactMarkdown from 'react-markdown';
import type { Components } from 'react-markdown';
import remarkGfm from 'remark-gfm';
import CodeBlock from '@/components/docs/code-block';
import { LinkIcon } from '@/components/docs/docs-icons';
import { ExternalLinkIcon } from '@/components/icons';
import { translate } from '@/lib/i18n';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

// [&_code]:text-inherit: code inside a link (a class name, for example) takes the link colour.
const linkClasses = `rounded-sm font-medium text-accent underline decoration-accent/30 underline-offset-[3px] break-words hover:text-accent-hover hover:decoration-accent [&_code]:text-inherit ${focusRing}`;

/** The part of a hast node (the HTML tree react-markdown builds) that the heading plugin needs. */
type HastNode = {
    type: string;
    tagName?: string;
    value?: string;
    properties?: Record<string, unknown>;
    children?: HastNode[];
};

function hastText(node: HastNode): string {
    return node.type === 'text' ? (node.value ?? '') : (node.children ?? []).map(hastText).join('');
}

/** "Data flow & queues" -> "data-flow-queues" (accents dropped, so ids stay plain ASCII). */
export function slugify(text: string): string {
    const slug = text
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

    return slug === '' ? 'section' : slug;
}

/**
 * Gives every h2 and h3 an `id` (the slug of its text; a repeated title gets -2, -3, ...).
 * It runs on the tree, once per document, so the ids are the same on every render and the
 * "On this page" list can read them straight from the page.
 */
function addHeadingIds() {
    return (tree: HastNode) => {
        const used = new Map<string, number>();

        const visit = (node: HastNode) => {
            if (node.tagName === 'h2' || node.tagName === 'h3') {
                const slug = slugify(hastText(node));
                const count = (used.get(slug) ?? 0) + 1;

                used.set(slug, count);
                node.properties = { ...node.properties, id: count === 1 ? slug : `${slug}-${count}` };
            }

            node.children?.forEach(visit);
        };

        visit(tree);
    };
}

/** Plain text of React children (headings can contain code or emphasis). */
function childrenText(children: ReactNode): string {
    if (typeof children === 'string' || typeof children === 'number') {
        return String(children);
    }

    if (Array.isArray(children)) {
        return children.map(childrenText).join('');
    }

    if (isValidElement<{ children?: ReactNode }>(children)) {
        return childrenText(children.props.children);
    }

    return '';
}

/** A heading with a hover link to itself, so a section can be shared. */
function SectionHeading({ level, id, className, children }: { level: 'h2' | 'h3'; id?: string; className: string; children?: ReactNode }) {
    const Tag = level;

    return (
        <Tag id={id} className={`group ${className}`}>
            {children}
            {id && (
                <a
                    href={`#${id}`}
                    aria-label={translate('docs.sectionLink', { title: childrenText(children) })}
                    className={`ml-2 inline-block rounded-sm align-middle text-ink-subtle opacity-0 group-hover:opacity-100 hover:text-accent focus-visible:opacity-100 ${focusRing}`}
                >
                    <LinkIcon className="size-4" />
                </a>
            )}
        </Tag>
    );
}

/** Pulls the language (and an optional title after it) out of the ```lang title fence of a code block. */
function fenceLabel(codeProps: { className?: string }, meta: unknown): string | null {
    const language = /language-([\w+#.-]+)/.exec(codeProps.className ?? '')?.[1];
    const title = typeof meta === 'string' ? meta.trim() : '';

    return [language, title].filter(Boolean).join('  ') || null;
}

/** A picture the app itself stored for the repository ("/repositories/1/docs/pictures/readme-2?v=abc1234#800x600"). */
const STORED_PICTURE = /^\/repositories\/\d+\/docs\/pictures\/[a-z0-9-]+(\?v=[0-9a-f]+)?(#\d+x\d+)?$/;

/**
 * Shows a stored README picture, lazily, with the size from the "#WxH" end of its address so the space is reserved.
 * Pictures of any other address are never loaded (see the img element below).
 */
function StoredPicture({ src, alt }: { src: string; alt: string }) {
    const size = /#(\d+)x(\d+)$/.exec(src);
    const url = src.replace(/#.*$/, '');

    return (
        <img
            src={url}
            alt={alt}
            width={size ? Number(size[1]) : undefined}
            height={size ? Number(size[2]) : undefined}
            loading="lazy"
            decoding="async"
            className="mt-5 h-auto max-h-96 w-auto max-w-full rounded-lg border border-line bg-surface-2"
        />
    );
}

/**
 * How each Markdown element looks. No typography plugin is installed, so the style is given here,
 * element by element, on the 760 px reading column. Links:
 * - to a page of the app ("/repositories/...") are Inertia links, with no page reload;
 * - to another site (GitHub) open in a new tab without access to our page (rel="noopener noreferrer").
 * Images are not loaded (they would come from other sites): only a link to them appears, except the README pictures
 * the app stored itself (StoredPicture).
 */
const components: Components = {
    h1: ({ node, ...props }) => <h1 className="mt-0 font-display text-title break-words text-ink" {...props} />,
    h2: ({ node, id, children }) => (
        <SectionHeading level="h2" id={id} className="mt-12 scroll-mt-24 font-display text-heading text-ink first:mt-0">
            {children}
        </SectionHeading>
    ),
    h3: ({ node, id, children }) => (
        <SectionHeading level="h3" id={id} className="mt-8 scroll-mt-24 text-lg font-semibold tracking-tight text-ink first:mt-0">
            {children}
        </SectionHeading>
    ),
    h4: ({ node, ...props }) => <h4 className="mt-6 text-base font-semibold text-ink first:mt-0" {...props} />,
    p: ({ node, ...props }) => <p className="mt-5 leading-7 text-ink first:mt-0" {...props} />,
    a: ({ node, href = '', children, ...props }) => {
        if (href.startsWith('/') && !href.startsWith('//')) {
            return (
                <Link href={href} prefetch className={linkClasses}>
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
                <ExternalLinkIcon className="ml-1 inline size-3.5 align-[-0.1em]" />
                <span className="sr-only"> {translate('common.opensInNewTab')}</span>
            </a>
        );
    },
    ul: ({ node, ...props }) => <ul className="mt-5 list-disc space-y-2 pl-5 marker:text-ink-subtle" {...props} />,
    ol: ({ node, ...props }) => <ol className="mt-5 list-decimal space-y-2 pl-5 marker:text-ink-subtle" {...props} />,
    li: ({ node, ...props }) => <li className="leading-7 text-ink [&>ol]:mt-2 [&>ul]:mt-2" {...props} />,
    blockquote: ({ node, ...props }) => <blockquote className="mt-5 border-l-2 border-accent pl-4 text-ink-muted" {...props} />,
    // Inline code; inside a code block the CodeBlock styles reset this.
    code: ({ node, ...props }) => (
        <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono text-[0.8125em] break-words text-ink" {...props} />
    ),
    pre: ({ node, children }) => {
        const codeElement = isValidElement<ComponentProps<'code'>>(children) ? children : null;
        const meta = (node?.children?.[0] as { data?: { meta?: unknown } } | undefined)?.data?.meta;

        return (
            <CodeBlock code={childrenText(codeElement?.props.children ?? children).replace(/\n$/, '')} label={fenceLabel(codeElement?.props ?? {}, meta)}>
                {children}
            </CodeBlock>
        );
    },
    // "relative": the sr-only texts in a table (absolutely positioned) stay inside the scrolling box,
    // otherwise they would widen the whole page on a phone.
    table: ({ node, ...props }) => (
        <div className="relative mt-5 overflow-x-auto rounded-lg border border-line">
            <table className="w-full text-left text-sm" {...props} />
        </div>
    ),
    th: ({ node, ...props }) => (
        <th className="bg-surface-2 px-3 py-2 text-left text-[0.8125rem] font-medium whitespace-nowrap text-ink-muted" {...props} />
    ),
    td: ({ node, ...props }) => <td className="border-t border-line-subtle px-3 py-2 align-top text-ink-muted first:text-ink" {...props} />,
    hr: () => <hr className="my-10 border-line" />,
    img: ({ src, alt }) =>
        typeof src === 'string' && STORED_PICTURE.test(src) ? (
            <StoredPicture src={src} alt={alt ?? ''} />
        ) : typeof src === 'string' ? (
            <a href={src} target="_blank" rel="noopener noreferrer" className={linkClasses}>
                {alt ? translate('docs.imageNamed', { alt }) : translate('docs.image')}<span className="sr-only"> {translate('common.opensInNewTab')}</span>
            </a>
        ) : null,
};

type Props = {
    /** The Markdown text (GitHub-flavored: tables, task lists, strikethrough). */
    children: string;
};

/**
 * Shows the generated documentation's Markdown. HTML in the text is never shown (`skipHtml`) and
 * react-markdown removes dangerous addresses (such as `javascript:`) itself, so text that comes from a
 * foreign repository cannot run code in the page.
 */
export default function Markdown({ children }: Props) {
    return (
        <div className="min-w-0 text-base">
            <ReactMarkdown remarkPlugins={[remarkGfm]} rehypePlugins={[addHeadingIds]} skipHtml components={components}>
                {children}
            </ReactMarkdown>
        </div>
    );
}
