import { useEffect, useState } from 'react';
import Markdown from '@/components/docs/markdown';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate, shortSha } from '@/lib/format';
import { translate } from '@/lib/i18n';
import type { DocumentPage } from '@/types/docs';

type Props = {
    document: DocumentPage;
    /** „owner/name”, pentru legătura spre commitul din care e scrisă pagina. */
    repositoryFullName: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const secondaryButtonClasses = `inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`;

/** Cine a scris pagina, pe scurt: Claude (cu modelul și calea, abonament sau API) sau analiza codului. */
function authorLabel(document: DocumentPage): string {
    if (document.writer === 'template') {
        return translate('docs.author.template');
    }

    const model = document.model ? ` (${document.model})` : '';

    if (document.ai_driver === 'claude-code') {
        return translate('docs.author.claudeSubscription', { model });
    }

    if (document.ai_driver === 'claude') {
        return translate('docs.author.claudeApi', { model });
    }

    return translate('docs.author.claude', { model });
}

/**
 * O pagină de documentație: avertismentul când e depășită sau scrisă automat în locul variantei cu AI, cine a scris-o
 * (și câți tokeni a folosit), butoanele README-ului („Copiază”, „Descarcă README.md”) și textul Markdown.
 * Componenta e de sine stătătoare, ca s-o poată folosi și alte ecrane (de exemplu spațiul de lucru al repository-ului).
 */
export default function DocsContent({ document, repositoryFullName }: Props) {
    const { t, plural, rich } = useI18n();

    return (
        <article className="min-w-0">
            {/* Două feluri de avertisment: textul lui Claude a rămas în urma codului, sau pagina e scrisă de șablon în locul lui Claude. */}
            {document.is_stale && (
                <div role="status" className="mb-6 rounded-lg border border-warning-line bg-warning-soft px-4 py-3 text-sm text-warning-ink">
                    <p className="font-semibold">
                        {document.writer === 'ai' ? t('docs.stale.ai') : t('docs.stale.template')}
                    </p>
                    {document.stale_reason && <p className="mt-1">{document.stale_reason}</p>}
                </div>
            )}

            <div className="mb-6 flex flex-col gap-3 border-b border-line pb-4">
                <p className="text-sm text-ink-subtle">
                    {authorLabel(document)}
                    {document.writer === 'ai' && (
                        <>
                            {' · '}
                            {t('docs.tokens', {
                                input: plural('tokens', document.input_tokens),
                                output: plural('tokens', document.output_tokens),
                            })}
                        </>
                    )}
                    {document.source_sha && (
                        <>
                            {' · '}
                            {rich('docs.sourceCommit', {
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
                        </>
                    )}
                    {document.generated_at && (
                        <>
                            {' · '}
                            <time dateTime={document.generated_at}>{formatDate(document.generated_at)}</time>
                        </>
                    )}
                </p>

                {document.kind === 'readme' && document.download_url && (
                    <div className="flex flex-wrap gap-2">
                        <CopyButton text={document.markdown} />
                        <a href={document.download_url} download="README.md" className={secondaryButtonClasses}>
                            {t('docs.downloadReadme')}
                        </a>
                    </div>
                )}
            </div>

            <Markdown>{document.markdown}</Markdown>
        </article>
    );
}

type CopyState = 'idle' | 'copied' | 'failed';

/**
 * „Copiază”: pune textul în clipboard și spune 2 secunde dacă a mers. Mesajul e într-o zonă aria-live,
 * ca să-l audă și cititoarele de ecran.
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
