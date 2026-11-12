import { useEffect, useRef } from 'react';
import AnswerMarkdown from '@/components/assistant/answer-markdown';
import CitationChip, { lineRange } from '@/components/assistant/citation-chip';
import { useI18n } from '@/hooks/use-i18n';
import type { Assistant } from '@/hooks/use-assistant';
import type { AssistantCitation, AssistantReply } from '@/types';

type Props = {
    assistant: Assistant;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Conversația cu asistentul: întrebările (în dreapta), răspunsurile (în stânga, cu citări și surse),
 * starea „se scrie răspunsul” și eroarea cu „Încearcă din nou”.
 *
 * Când apare ceva nou, îl aducem în vedere: un răspuns nou de la începutul lui (poate fi lung),
 * iar întrebarea, starea de așteptare și eroarea de jos, deasupra barei asistentului
 * (scroll-mb lasă loc barei care stă peste josul paginii).
 *
 * E un „log” pentru cititoarele de ecran: mesajele noi sunt anunțate fără să mute focusul.
 */
export default function AssistantThread({ assistant }: Props) {
    const { t } = useI18n();
    const { messages, isAsking, error, retry } = assistant;
    const lastAnswerRef = useRef<HTMLDivElement>(null);
    const endRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (messages.at(-1)?.role === 'assistant') {
            lastAnswerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (messages.length > 0 || error !== null) {
            endRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, [messages, isAsking, error]);

    if (messages.length === 0 && !isAsking && error === null) {
        return null;
    }

    return (
        <div role="log" aria-label={t('workspace.dock.conversation')} className="flex flex-col gap-4">
            {messages.map((message, index) =>
                message.role === 'user' ? (
                    <div key={message.id} className="flex justify-end">
                        <p className="max-w-[85%] rounded-2xl rounded-br-sm bg-accent-strong px-4 py-2.5 text-sm whitespace-pre-wrap text-on-accent">
                            <span className="sr-only">{t('assistant.thread.youAsked')}</span>
                            {message.content}
                        </p>
                    </div>
                ) : (
                    <div key={message.id} ref={index === messages.length - 1 ? lastAnswerRef : undefined} className="flex scroll-mt-4 flex-col">
                        <AssistantAnswer reply={message.reply} />
                    </div>
                ),
            )}

            {isAsking && (
                <div role="status" className="flex items-center gap-3 self-start rounded-2xl rounded-bl-sm border border-line bg-surface px-4 py-3 text-sm text-ink-muted">
                    <span aria-hidden="true" className="flex gap-1">
                        {[0, 150, 300].map((delay) => (
                            <span key={delay} className="size-1.5 rounded-full bg-accent motion-safe:animate-bounce" style={{ animationDelay: `${delay}ms` }} />
                        ))}
                    </span>
                    {t('assistant.thread.searching')}
                </div>
            )}

            {error !== null && !isAsking && (
                <div
                    role="alert"
                    className="flex flex-col gap-3 self-start rounded-2xl rounded-bl-sm border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink sm:flex-row sm:items-center"
                >
                    <p>{error}</p>
                    <button
                        type="button"
                        onClick={retry}
                        className="shrink-0 cursor-pointer self-start rounded-md bg-surface px-3 py-1.5 font-medium text-danger-ink ring-1 ring-danger-line ring-inset hover:bg-danger-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger sm:self-auto"
                    >
                        {t('assistant.thread.retry')}
                    </button>
                </div>
            )}

            <div ref={endRef} className="scroll-mb-56" />
        </div>
    );
}

/** Un răspuns: nota (dacă e fără AI), textul cu citări și sursele lui. */
function AssistantAnswer({ reply }: { reply: AssistantReply }) {
    const { t } = useI18n();
    const cited = reply.citations.filter((citation) => citation.cited);
    const notCited = reply.citations.filter((citation) => !citation.cited);

    return (
        <article className="flex max-w-full min-w-0 flex-col gap-3 self-start rounded-2xl rounded-bl-sm border border-line bg-surface px-4 py-3 shadow-xs sm:max-w-[92%]">
            <h3 className="sr-only">{t('assistant.thread.answerHeading')}</h3>

            {reply.notice && <p className="rounded-md border border-info-line bg-info-soft px-3 py-2 text-sm text-info-ink">{reply.notice}</p>}

            <AnswerMarkdown markdown={reply.answer_markdown} citations={reply.citations} />

            {cited.length > 0 && <SourceList title={t('assistant.thread.sources')} citations={cited} />}

            {notCited.length > 0 && (
                <details className="text-sm">
                    <summary className={`cursor-pointer rounded-sm text-ink-subtle select-none hover:text-ink ${focusRing}`}>
                        {cited.length > 0 ? t('assistant.thread.otherFragments') : t('assistant.thread.fragmentsConsulted')} ({notCited.length})
                    </summary>
                    <div className="mt-2">
                        <SourceList citations={notCited} />
                    </div>
                </details>
            )}

            <p className="text-xs text-ink-subtle">
                {reply.mode === 'ai'
                    ? reply.citations.length === 1
                        ? t('assistant.thread.aiNoteOne')
                        : t('assistant.thread.aiNoteMany', { count: reply.citations.length })
                    : t('assistant.thread.noAiNote')}
            </p>
        </article>
    );
}

/** Lista surselor: numărul, fișierul (legătură spre liniile de pe GitHub), liniile și simbolul. */
function SourceList({ title, citations }: { title?: string; citations: AssistantCitation[] }) {
    const { t } = useI18n();

    return (
        <div className="border-t border-line-subtle pt-3">
            {title && <p className="text-xs font-semibold tracking-wide text-ink-subtle uppercase">{title}</p>}
            <ol className={`flex flex-col gap-1.5 text-sm ${title ? 'mt-2' : ''}`}>
                {citations.map((citation) => (
                    <li key={citation.index} className="flex min-w-0 items-baseline gap-2">
                        <CitationChip citation={citation} />
                        <span className="min-w-0 break-all text-ink-muted">
                            {citation.url ? (
                                <a
                                    href={citation.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={`rounded-sm font-mono text-[0.8125rem] text-ink hover:text-accent-hover hover:underline ${focusRing}`}
                                >
                                    {citation.path}
                                    <span className="sr-only"> {t('assistant.thread.onGitHub')}</span>
                                </a>
                            ) : (
                                <span className="font-mono text-[0.8125rem] text-ink">{citation.path}</span>
                            )}
                            <span className="text-ink-subtle">
                                {' '}
                                · {t('assistant.thread.lines', { range: lineRange(citation) })}
                                {citation.symbol_name && ` · ${citation.symbol_name}`}
                            </span>
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
