import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AnswerMarkdown from '@/components/assistant/answer-markdown';
import { CheckIcon, LinkIcon } from '@/components/assistant/assistant-icons';
import CitationChip, { lineRange } from '@/components/assistant/citation-chip';
import { AppLogoMark } from '@/components/app-logo';
import { useI18n } from '@/hooks/use-i18n';
import type { Assistant } from '@/hooks/use-assistant';
import type { AssistantCitation, AssistantReply, Workspace } from '@/types';

type Props = {
    assistant: Assistant;
    /** Real suggested questions, for the follow-ups; by default the repository's own (workspace.assistant.starters). */
    starters?: string[];
};

/** At most this many follow-up questions are offered under an answer. */
const MAX_FOLLOW_UPS = 3;

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** The suggested questions of the repository the page belongs to (none on a page without a workspace). */
export function useStarterQuestions(starters?: string[]): string[] {
    const page = usePage<{ workspace?: Workspace }>();

    return starters ?? page.props.workspace?.assistant.starters ?? [];
}

/**
 * The conversation: questions on the right (with a link that asks the same question), answers as plain
 * prose hanging on a rail whose ring is the logo mark. The waiting state is one honest label on the rail
 * (no fake typing), and an error offers "Try again".
 *
 * Nothing here depends on the width of its container, so it can sit in a page column or in a panel.
 * A new question is scrolled into view; an answer then appears below it without moving the page.
 * It is a "log" for screen readers: new messages are announced without moving the focus.
 */
export default function AssistantThread({ assistant, starters }: Props) {
    const { t } = useI18n();
    const { messages, isAsking, error, retry } = assistant;
    const suggestions = useStarterQuestions(starters);
    const lastQuestionRef = useRef<HTMLDivElement>(null);
    const endRef = useRef<HTMLDivElement>(null);
    const lastMessage = messages.at(-1);

    useEffect(() => {
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth';

        if (lastMessage?.role === 'user') {
            lastQuestionRef.current?.scrollIntoView({ behavior, block: 'start' });
        } else if (error !== null) {
            endRef.current?.scrollIntoView({ behavior, block: 'nearest' });
        }
    }, [lastMessage?.id, lastMessage?.role, error]);

    if (messages.length === 0 && !isAsking && error === null) {
        return null;
    }

    const lastQuestionId = messages.filter((message) => message.role === 'user').at(-1)?.id;
    const askedQuestions = new Set(messages.flatMap((message) => (message.role === 'user' ? [message.content.trim().toLowerCase()] : [])));
    const followUps = suggestions.filter((question) => !askedQuestions.has(question.trim().toLowerCase())).slice(0, MAX_FOLLOW_UPS);

    return (
        <div role="log" aria-label={t('workspace.dock.conversation')} className="flex min-w-0 flex-col gap-6">
            {messages.map((message, index) =>
                message.role === 'user' ? (
                    <Question key={message.id} content={message.content} assistant={assistant} innerRef={message.id === lastQuestionId ? lastQuestionRef : undefined} />
                ) : (
                    <AssistantAnswer
                        key={message.id}
                        reply={message.reply}
                        followUps={index === messages.length - 1 && !isAsking ? followUps : []}
                        onAsk={assistant.ask}
                    />
                ),
            )}

            {isAsking && (
                <Rail isWaiting>
                    <p role="status" className="py-0.5 text-[0.9375rem] text-ink-muted">
                        {t('assistant.thread.searching')}
                    </p>
                </Rail>
            )}

            {error !== null && !isAsking && (
                <div role="alert" className="flex flex-col gap-3 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink sm:flex-row sm:items-center">
                    <p className="min-w-0 flex-1">{error}</p>
                    <button
                        type="button"
                        onClick={retry}
                        className={`pressable shrink-0 cursor-pointer self-start rounded-md bg-surface px-3 py-1.5 font-medium text-danger-ink ring-1 ring-danger-line ring-inset hover:bg-danger-soft sm:self-auto ${focusRing}`}
                    >
                        {t('assistant.thread.retry')}
                    </button>
                </div>
            )}

            <div ref={endRef} />
        </div>
    );
}

/** A question: right-aligned, with a small action that copies a link which asks it again. */
function Question({ content, assistant, innerRef }: { content: string; assistant: Assistant; innerRef?: React.Ref<HTMLDivElement> }) {
    const { t } = useI18n();
    const [state, setState] = useState<'idle' | 'copied' | 'failed'>('idle');

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(assistant.questionLink(content));
            setState('copied');
        } catch {
            setState('failed');
        }

        window.setTimeout(() => setState('idle'), 2500);
    };

    return (
        <div ref={innerRef} className="flex scroll-mt-20 animate-rise flex-col items-end gap-1">
            <p className="max-w-[85%] rounded-xl rounded-br-sm bg-accent-soft px-4 py-2.5 text-[0.9375rem] break-words whitespace-pre-wrap text-ink">
                <span className="sr-only">{t('assistant.thread.youAsked')}</span>
                {content}
            </p>
            <button
                type="button"
                onClick={copyLink}
                className={`inline-flex cursor-pointer items-center gap-1.5 rounded-sm px-1 text-[0.8125rem] text-ink-subtle hover:text-ink ${focusRing}`}
            >
                {state === 'copied' ? <CheckIcon className="size-3.5" /> : <LinkIcon className="size-3.5" />}
                {t('assistant.thread.copyLink')}
            </button>
            <span role="status" className="sr-only">
                {state === 'copied' ? t('assistant.thread.linkCopied') : state === 'failed' ? t('assistant.thread.linkCopyFailed') : ''}
            </span>
            {state !== 'idle' && (
                <span aria-hidden="true" className="text-[0.8125rem] text-ink-subtle">
                    {state === 'copied' ? t('assistant.thread.linkCopied') : t('assistant.thread.linkCopyFailed')}
                </span>
            )}
        </div>
    );
}

/** The vertical rail of an answer: the logo mark on top (with a halo while waiting) and a thin line down the side. */
function Rail({ children, isWaiting = false }: { children: React.ReactNode; isWaiting?: boolean }) {
    return (
        <div className="flex animate-rise gap-3">
            <div aria-hidden="true" className="flex flex-col items-center gap-1.5 pt-1">
                <span className="relative flex size-6 shrink-0 items-center justify-center">
                    {isWaiting && <span className="absolute inset-0 rounded-full ring-2 ring-accent motion-safe:animate-halo" />}
                    <AppLogoMark shared={false} className="relative size-6" />
                </span>
                <span className="w-px flex-1 bg-line" />
            </div>
            <div className="min-w-0 flex-1 pb-1">{children}</div>
        </div>
    );
}

/** One answer: the mode banner, the text with its citations, the sources, the usage and the follow-up questions. */
function AssistantAnswer({ reply, followUps, onAsk }: { reply: AssistantReply; followUps: string[]; onAsk: (question: string) => boolean }) {
    const { t } = useI18n();
    const cited = reply.citations.filter((citation) => citation.cited);
    const notCited = reply.citations.filter((citation) => !citation.cited);
    const found = reply.citations.length > 0;

    return (
        <Rail>
            <article className="flex min-w-0 flex-col gap-4">
                <h3 className="sr-only">{t('assistant.thread.answerHeading')}</h3>

                {reply.mode === 'retrieval' && found && (
                    <div className="rounded-lg border border-line bg-surface-2 px-3.5 py-2.5 text-sm text-ink-muted">
                        <p className="font-medium text-ink">{t('assistant.thread.retrievalBanner')}</p>
                        {reply.notice && <p className="mt-0.5">{reply.notice}</p>}
                    </div>
                )}

                {found ? (
                    <AnswerMarkdown markdown={reply.answer_markdown} citations={reply.citations} />
                ) : (
                    <div className="flex flex-col gap-1">
                        <p className="text-base font-semibold text-ink">{t('assistant.thread.notFoundTitle')}</p>
                        <p className="text-[0.9375rem] text-ink-muted">{t('assistant.thread.notFoundText')}</p>
                    </div>
                )}

                {cited.length > 0 && <SourceList title={t('assistant.thread.sources')} citations={cited} />}

                {notCited.length > 0 && (
                    <details className="text-sm">
                        <summary className={`cursor-pointer rounded-sm text-[0.8125rem] font-medium text-ink-subtle select-none hover:text-ink ${focusRing}`}>
                            {cited.length > 0 ? t('assistant.thread.otherFragments') : t('assistant.thread.fragmentsConsulted')} ({notCited.length})
                        </summary>
                        <div className="mt-2">
                            <SourceList citations={notCited} />
                        </div>
                    </details>
                )}

                {reply.mode === 'ai' && (
                    <p className="text-[0.8125rem] text-ink-subtle">
                        {reply.citations.length === 1 ? t('assistant.thread.aiNoteOne') : t('assistant.thread.aiNoteMany', { count: reply.citations.length })}
                    </p>
                )}

                {reply.usage && (
                    <details className="text-[0.8125rem] text-ink-subtle">
                        <summary className={`cursor-pointer rounded-sm select-none hover:text-ink ${focusRing}`}>{t('assistant.thread.usageDetails')}</summary>
                        <p className="mt-1">
                            {t('assistant.thread.usageLine', { input: reply.usage.input_tokens, output: reply.usage.output_tokens, model: reply.usage.model })}
                        </p>
                    </details>
                )}

                {followUps.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <p className="text-[0.8125rem] font-medium text-ink-subtle">{t('assistant.thread.followUps')}</p>
                        <ul className="flex flex-col items-start gap-1.5">
                            {followUps.map((question) => (
                                <li key={question} className="max-w-full">
                                    <button
                                        type="button"
                                        onClick={() => onAsk(question)}
                                        className={`pressable cursor-pointer rounded-md bg-surface px-3 py-1.5 text-left text-sm text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`}
                                    >
                                        {question}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </article>
        </Rail>
    );
}

/** The sources: the number (hover for the lines), `path:start–end` linking to GitHub, and the symbol. */
function SourceList({ title, citations }: { title?: string; citations: AssistantCitation[] }) {
    const { t } = useI18n();

    return (
        <div className={title ? 'border-t border-line-subtle pt-3' : ''}>
            {title && <p className="text-[0.8125rem] font-medium text-ink-subtle">{title}</p>}
            <ol className={`flex flex-col gap-1.5 text-sm ${title ? 'mt-2' : ''}`}>
                {citations.map((citation) => (
                    <li key={citation.index} className="flex min-w-0 items-baseline gap-2">
                        <CitationChip citation={citation} />
                        <span className="min-w-0 break-all">
                            {citation.url ? (
                                <a
                                    href={citation.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={`rounded-sm font-mono text-[0.8125rem] text-ink hover:text-accent hover:underline ${focusRing}`}
                                >
                                    {citation.path}:{lineRange(citation)}
                                    <span className="sr-only"> {t('assistant.thread.onGitHub')}</span>
                                </a>
                            ) : (
                                <span className="font-mono text-[0.8125rem] text-ink">
                                    {citation.path}:{lineRange(citation)}
                                </span>
                            )}
                            {citation.symbol_name && <span className="text-ink-subtle"> · {citation.symbol_name}</span>}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
