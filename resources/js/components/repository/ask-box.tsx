import { Link, router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { MIN_QUESTION_LENGTH } from '@/hooks/use-assistant';
import { ArrowRightIcon, AskIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { RepositoryItem } from '@/types';

/** The id of the ask box: the workspace hides its floating "Ask" button while this box is on screen. */
export const HERO_ASK_ID = 'showcase-ask';

type Props = {
    repository: RepositoryItem;
    /** Up to three real questions about the repository's modules (workspace.assistant.starters). */
    starters: string[];
};

/** The Ask page with a question to ask as soon as it opens (the page reads `?ask=`). */
export function askUrl(repositoryId: number, question: string): string {
    return `/repositories/${repositoryId}/assistant?ask=${encodeURIComponent(question)}`;
}

/**
 * The Overview's ask box: a large field "Ask anything about {name}" and the repository's own suggested questions
 * as chips. Sending (or a chip) opens the Ask page with the question, where the answer is written with citations.
 */
export default function AskBox({ repository, starters }: Props) {
    const { t } = useI18n();
    const inputId = useId();
    const [question, setQuestion] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const canSend = question.trim().length >= MIN_QUESTION_LENGTH;

    function handleSubmit(event: FormEvent) {
        event.preventDefault();

        // A question too short to ask sends the visitor back to the field instead of greying the button out.
        if (canSend) {
            router.visit(askUrl(repository.id, question.trim()));
        } else {
            inputRef.current?.focus();
        }
    }

    return (
        <div id={HERO_ASK_ID}>
            <form
                onSubmit={handleSubmit}
                className="flex items-center gap-2 rounded-xl border border-line-strong bg-surface p-1.5 pl-4 shadow-card has-[input:focus-visible]:outline-2 has-[input:focus-visible]:-outline-offset-1 has-[input:focus-visible]:outline-accent"
            >
                <AskIcon className="size-5 shrink-0 text-ink-subtle" />
                <label htmlFor={inputId} className="sr-only">
                    {t('overview.showcase.askLabel', { repository: repository.full_name })}
                </label>
                <input
                    id={inputId}
                    ref={inputRef}
                    type="text"
                    value={question}
                    onChange={(event) => setQuestion(event.target.value)}
                    placeholder={t('overview.showcase.askPlaceholder', { name: repository.name })}
                    autoComplete="off"
                    enterKeyHint="send"
                    className="h-11 min-w-0 flex-1 bg-transparent text-base text-ink placeholder:text-ink-subtle focus:outline-none"
                />
                <button
                    type="submit"
                    className="pressable inline-flex h-11 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg bg-accent-strong px-4 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    <span className="max-sm:sr-only">{t('overview.showcase.askSend')}</span>
                    <ArrowRightIcon className="size-4" />
                </button>
            </form>

            {starters.length > 0 && (
                <ul aria-label={t('overview.showcase.suggested')} className="mt-3 flex flex-wrap gap-2">
                    {starters.map((starter) => (
                        <li key={starter} className="min-w-0 max-w-full">
                            <Link
                                href={askUrl(repository.id, starter)}
                                className="pressable block max-w-full truncate rounded-full border border-line bg-surface px-3 py-1 text-[0.8125rem] text-ink-muted hover:border-line-strong hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            >
                                {starter}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
