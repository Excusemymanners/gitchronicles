import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { CheckIcon, CopyIcon } from '@/components/docs/docs-icons';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    /** The raw code, exactly what the copy button puts on the clipboard. */
    code: string;
    /** The fence language (```php) or, when the fence has a title, that path; `null` = no header strip. */
    label: string | null;
    children: ReactNode;
};

/**
 * A fenced code block: the code in a scrollable box with a copy button in the corner.
 * There is no syntax highlighting on purpose (no new dependency); one ink colour keeps the page calm.
 * The strip with the language only appears when the fence names one.
 */
export default function CodeBlock({ code, label, children }: Props) {
    const { t } = useI18n();
    const [copied, setCopied] = useState(false);

    // The check mark goes back to the copy icon after 1.5 s.
    useEffect(() => {
        if (!copied) {
            return;
        }

        const timer = window.setTimeout(() => setCopied(false), 1500);

        return () => window.clearTimeout(timer);
    }, [copied]);

    async function copy() {
        try {
            await navigator.clipboard.writeText(code);
            setCopied(true);
        } catch {
            // Clipboard blocked (for example on plain http): nothing to confirm, the text can still be selected.
        }
    }

    return (
        <div className="group relative mt-5 rounded-lg border border-line bg-surface-2/60">
            {label && (
                <div className="border-b border-line px-4 py-2 pr-12 font-mono text-[0.8125rem] text-ink-subtle">{label}</div>
            )}

            <pre className="overflow-x-auto p-4 font-mono text-[0.8125rem] leading-6 text-ink [&>code]:bg-transparent [&>code]:p-0 [&>code]:text-[1em] [&>code]:break-normal">
                {children}
            </pre>

            <button
                type="button"
                onClick={copy}
                aria-label={t('docs.codeCopy')}
                className={`pressable absolute top-1.5 right-1.5 inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-ink-subtle hover:bg-surface hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${copied ? 'text-success' : ''}`}
            >
                {copied ? <CheckIcon /> : <CopyIcon />}
                <span aria-live="polite" className="sr-only">
                    {copied ? t('docs.copied') : ''}
                </span>
            </button>
        </div>
    );
}
