import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ClockIcon } from '@/components/docs/docs-icons';
import { ArchitectureFigure, FactsStrip, OpeningPicture, ProjectDescription, ReadmeGallery, UpdateTimeline } from '@/components/docs/docs-visuals';
import Markdown from '@/components/docs/markdown';
import NeighbourhoodDiagram from '@/components/docs/neighbourhood-diagram';
import OnThisPage, { useActiveHeading, usePageHeadings } from '@/components/docs/on-this-page';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import { shortSha } from '@/lib/format';
import { formatDay, formatUsd } from '@/lib/history-format';
import type { DocumentPage, DocumentVisuals } from '@/types/docs';

type Props = {
    document: DocumentPage;
    /** "owner/name", for the links to the commit the page was written from. */
    repositoryFullName: string;
    /** The pictures around the text (App\Documentation\DocumentVisuals); `null` or absent shows the text alone. */
    visuals?: DocumentVisuals | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const secondaryButtonClasses = `pressable inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`;

/**
 * Splits the leading "# Title" line off the Markdown. The page shows the title first, then the "who wrote it"
 * line, then the rest; a page without a leading title just starts with the line.
 */
function splitTitle(markdown: string): { title: string | null; body: string } {
    const match = /^\s*#\s+(.+?)\s*#*\s*(?:\r?\n|$)/.exec(markdown);

    return match ? { title: match[1], body: markdown.slice(match[0].length) } : { title: null, body: markdown };
}

/**
 * Cuts the Markdown after the "## How it solves it" section (where the text talks about structure), or after the first
 * "## " section when there is none, so the architecture diagram can sit between the two halves. The text is only
 * split, never changed. Headings inside code blocks do not count. `null` when there is no second section to cut at.
 */
function splitForDiagram(markdown: string): [string, string] | null {
    const lines = markdown.split('\n');
    const starts: { index: number; heading: string }[] = [];
    let inCode = false;

    lines.forEach((line, index) => {
        if (/^\s{0,3}(```|~~~)/.test(line)) {
            inCode = !inCode;
        }

        const heading = inCode ? null : /^##\s+(.+?)\s*#*\s*$/.exec(line);

        if (heading) {
            starts.push({ index, heading: heading[1] });
        }
    });

    if (starts.length === 0) {
        return null;
    }

    const target = Math.max(0, starts.findIndex((start) => /how it solves it/i.test(start.heading)));
    const cut = starts[target + 1]?.index ?? lines.length;

    return [lines.slice(0, cut).join('\n'), lines.slice(cut).join('\n')];
}

/** A title may hold `code` between backticks (a module path); show it as code instead of showing the backticks. */
function inlineCode(text: string): ReactNode[] {
    return text.split('`').map((part, index) =>
        index % 2 === 1 ? (
            <code key={index} className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-[0.75em]">
                {part}
            </code>
        ) : (
            part
        ),
    );
}

/**
 * One documentation page: the title, one quiet line saying who wrote it and from which commit, the stale banner
 * when the code moved on, the README buttons and the Markdown text. "On this page" lists the sections.
 * The component stands alone so other screens (the repository workspace) can use it too.
 */
export default function DocsContent({ document, repositoryFullName, visuals = null }: Props) {
    const { t } = useI18n();
    const [article, setArticle] = useState<HTMLElement | null>(null);
    const { title, body } = splitTitle(document.markdown);
    const headings = usePageHeadings(article, body);
    const activeId = useActiveHeading(headings, article);
    const hasPictures = visuals !== null && (document.kind === 'overview' || document.kind === 'readme');
    const parts = hasPictures && visuals.architecture !== null ? splitForDiagram(body) : null;

    return (
        <article ref={setArticle} className="min-w-0">
            <OnThisPage headings={headings} activeId={activeId} />

            {title && <h1 className="font-display text-title break-words text-ink">{inlineCode(title)}</h1>}

            <Byline document={document} repositoryFullName={repositoryFullName} hasTitle={title !== null} />
            <GenerationDetails document={document} />

            {document.is_stale && <StaleBanner document={document} repositoryFullName={repositoryFullName} />}

            {document.kind === 'readme' && document.download_url && (
                <div className="mt-5 flex flex-wrap gap-2">
                    <CopyButton text={document.markdown} />
                    <a href={document.download_url} download="README.md" className={secondaryButtonClasses}>
                        {t('docs.downloadReadme')}
                    </a>
                    {document.download_zip_url && (
                        <a href={document.download_zip_url} className={secondaryButtonClasses}>
                            {t('docs.downloadReadmeZip')}
                        </a>
                    )}
                </div>
            )}

            {visuals && document.kind === 'module' && visuals.neighbourhood && <NeighbourhoodDiagram neighbourhood={visuals.neighbourhood} commit={visuals.commit} />}
            {hasPictures && <OpeningPicture visuals={visuals} repositoryFullName={repositoryFullName} />}
            {hasPictures && document.kind === 'overview' && <FactsStrip visuals={visuals} />}

            <div className="mt-8">
                {parts ? (
                    <>
                        <Markdown>{parts[0]}</Markdown>
                        <ArchitectureFigure visuals={visuals!} />
                        <div className="mt-8">
                            <Markdown>{parts[1]}</Markdown>
                        </div>
                    </>
                ) : (
                    <Markdown>{body}</Markdown>
                )}
            </div>

            {hasPictures && <ProjectDescription visuals={visuals} collapsed={document.kind === 'overview'} />}
            {hasPictures && <ReadmeGallery visuals={visuals} />}
            {hasPictures && document.kind === 'overview' && <UpdateTimeline visuals={visuals} />}
        </article>
    );
}

/** "Written by Claude from commit 3ac73c4, on Nov 10, 2026." The SHA links to the commit on GitHub. */
function Byline({ document, repositoryFullName, hasTitle }: { document: DocumentPage; repositoryFullName: string; hasTitle: boolean }) {
    const { t, rich } = useI18n();

    return (
        <p className={`${hasTitle ? 'mt-3' : ''} text-[0.8125rem] text-ink-subtle`}>
            {document.writer === 'template' ? t('docs.byline.template') : t('docs.byline.ai')}
            {document.source_sha &&
                rich('docs.byline.fromCommit', {
                    sha: (
                        <a
                            href={`https://github.com/${repositoryFullName}/commit/${document.source_sha}`}
                            target="_blank"
                            rel="noopener noreferrer"
                            title={document.source_sha}
                            className={`rounded-sm font-mono text-accent hover:text-accent-hover hover:underline ${focusRing}`}
                        >
                            {shortSha(document.source_sha)}
                            <span className="sr-only"> {t('docs.commitLink')}</span>
                        </a>
                    ),
                })}
            {document.generated_at &&
                rich('docs.byline.onDate', { date: <time dateTime={document.generated_at}>{formatDay(document.generated_at)}</time> })}
            .
        </p>
    );
}

/** Model, tokens and cost behind a click. Only the values we really have are listed; a page with none shows nothing. */
function GenerationDetails({ document }: { document: DocumentPage }) {
    const { t, plural } = useI18n();

    if (document.writer !== 'ai') {
        return null;
    }

    const rows: { label: string; value: string }[] = [];

    if (document.ai_driver === 'claude-code' || document.ai_driver === 'claude') {
        rows.push({ label: t('docs.details.via'), value: document.ai_driver === 'claude' ? t('docs.details.viaApi') : t('docs.details.viaSubscription') });
    }

    if (document.model) {
        rows.push({ label: t('docs.details.model'), value: document.model });
    }

    if (document.input_tokens > 0 || document.output_tokens > 0) {
        rows.push({
            label: t('docs.details.tokens'),
            value: t('docs.details.tokensValue', { input: plural('tokens', document.input_tokens), output: plural('tokens', document.output_tokens) }),
        });
    }

    if (document.cost_usd_equivalent !== null) {
        rows.push({ label: t('docs.details.cost'), value: formatUsd(document.cost_usd_equivalent) });
    }

    if (rows.length === 0) {
        return null;
    }

    return (
        <details className="mt-2 text-[0.8125rem] text-ink-subtle">
            <summary className={`w-fit cursor-pointer rounded-sm hover:text-ink ${focusRing}`}>{t('docs.details.title')}</summary>
            <dl className="mt-2 grid max-w-md grid-cols-[auto_1fr] gap-x-6 gap-y-1">
                {rows.map((row) => (
                    <div key={row.label} className="col-span-2 grid grid-cols-subgrid">
                        <dt>{row.label}</dt>
                        <dd className="text-ink-muted">{row.value}</dd>
                    </div>
                ))}
            </dl>
        </details>
    );
}

/** The warning above the text: Claude's page fell behind the code, or a template wrote it instead of Claude. */
function StaleBanner({ document, repositoryFullName }: { document: DocumentPage; repositoryFullName: string }) {
    const { t, rich } = useI18n();
    const sha = document.source_sha;
    const message =
        document.writer !== 'ai'
            ? t('docs.stale.template')
            : sha
              ? rich(document.kind === 'module' ? 'docs.stale.module' : 'docs.stale.project', {
                    sha: <code className="font-mono">{shortSha(sha)}</code>,
                })
              : t('docs.stale.ai');

    return (
        <div
            role="status"
            className="mt-6 flex animate-fade-in gap-3 rounded-lg border border-warning-line bg-warning-soft px-4 py-3 text-sm text-warning-ink"
        >
            <ClockIcon className="mt-0.5 size-4" />
            <div className="min-w-0">
                <p className="font-medium">{message}</p>
                {document.stale_reason && <p className="mt-1">{document.stale_reason}</p>}
                {sha && document.writer === 'ai' && (
                    <a
                        href={`https://github.com/${repositoryFullName}/compare/${sha}...HEAD`}
                        target="_blank"
                        rel="noopener noreferrer"
                        className={`mt-2 inline-flex items-center gap-1 rounded-sm font-medium underline underline-offset-[3px] hover:no-underline ${focusRing}`}
                    >
                        {t('docs.stale.seeChanges')}
                        <ExternalLinkIcon className="size-3.5" />
                        <span className="sr-only"> {t('common.opensInNewTab')}</span>
                    </a>
                )}
            </div>
        </div>
    );
}

type CopyState = 'idle' | 'copied' | 'failed';

/**
 * "Copy": puts the text on the clipboard and says for 2 seconds whether it worked. The message sits in an
 * aria-live region so screen readers announce it.
 */
function CopyButton({ text }: { text: string }) {
    const { t } = useI18n();
    const [state, setState] = useState<CopyState>('idle');

    useEffect(() => {
        if (state === 'idle') {
            return;
        }

        const timer = window.setTimeout(() => setState('idle'), 2000);

        return () => window.clearTimeout(timer);
    }, [state]);

    async function copy() {
        try {
            await navigator.clipboard.writeText(text);
            setState('copied');
        } catch {
            setState('failed');
        }
    }

    return (
        <button type="button" onClick={copy} className={secondaryButtonClasses}>
            <span aria-live="polite">{state === 'copied' ? t('docs.copied') : state === 'failed' ? t('docs.copyFailed') : t('docs.copy')}</span>
        </button>
    );
}
