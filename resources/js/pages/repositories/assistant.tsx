import { Head, router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { useAssistantConversation } from '@/components/assistant/assistant-provider';
import AssistantThread, { useStarterQuestions } from '@/components/assistant/assistant-thread';
import ChapterEmptyState from '@/components/repository/chapter-empty-state';
import { useI18n } from '@/hooks/use-i18n';
import RepositoryLayout from '@/layouts/repository-layout';
import { shortSha } from '@/lib/format';
import type { Completeness, RepositoryPageProps } from '@/types';

/**
 * Pagina „Asistent”: aceeași conversație ca în bara de jos a spațiului de lucru (AssistantProvider),
 * pe toată înălțimea capitolului, ca un răspuns lung să se citească ușor. Întrebările se pun tot din
 * bara de jos (AssistantDock), care pe pagina asta nu mai deschide panoul ei.
 */
export default function RepositoriesAssistant({ repository, workspace, completeness }: RepositoryPageProps) {
    const { t, rich } = useI18n();
    const conversation = useAssistantConversation();
    const hasConversation = conversation.messages.length > 0;
    const isReady = workspace.assistant.is_indexed;
    useAskFromUrl(isReady, conversation.ask);

    return (
        <RepositoryLayout>
            <Head title={t('assistant.pageTitle', { repository: repository.full_name })} />

            <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 sm:flex-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink">{t('assistant.heading')}</h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        {repository.last_sha
                            ? rich('assistant.introAtCommit', {
                                  sha: <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(repository.last_sha)}</code>,
                              })
                            : t('assistant.intro')}
                    </p>
                </div>

                {hasConversation && (
                    <button
                        type="button"
                        onClick={conversation.clear}
                        className="shrink-0 cursor-pointer self-start rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        {t('assistant.newConversation')}
                    </button>
                )}
            </div>

            <div className="mt-6">
                {hasConversation ? (
                    <AssistantThread assistant={conversation} />
                ) : !isReady && repository.status === 'ready' ? (
                    <NotIndexed completeness={completeness} />
                ) : isReady ? (
                    <EmptyThread ask={conversation.ask} />
                ) : (
                    <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        <p className="font-medium text-ink">{t('assistant.waitingTitle')}</p>
                        <p className="mt-2">{t('assistant.waitingText')}</p>
                    </div>
                )}
            </div>
        </RepositoryLayout>
    );
}

/**
 * Un repository gata al cărui cod nu e indexat: fie nu are fișiere cu cod sursă (asistentul răspunde doar
 * din cod), fie a fost procesat fără etapa indexării și o procesare completă o face.
 */
function NotIndexed({ completeness }: { completeness: Completeness }) {
    const { t } = useI18n();

    if (completeness.artifacts.assistant.state === 'not_applicable') {
        return (
            <ChapterEmptyState title={t('assistant.noCodeTitle')}>
                {t('assistant.noCodeText')}
            </ChapterEmptyState>
        );
    }

    return (
        <ChapterEmptyState title={t('assistant.notIndexedTitle')} offerRegenerate>
            {t('assistant.notIndexedText')}
        </ChapterEmptyState>
    );
}

/**
 * A shared link (`…/assistant?ask=<question>`) asks its question once the code is indexed, then the parameter is
 * removed from the address so that reloading the page does not ask again.
 */
function useAskFromUrl(isReady: boolean, ask: (question: string) => boolean) {
    const handled = useRef(false);

    useEffect(() => {
        if (!isReady || handled.current) {
            return;
        }

        const url = new URL(window.location.href);
        const question = url.searchParams.get('ask');

        if (question === null) {
            return;
        }

        handled.current = true;
        url.searchParams.delete('ask');
        // A client-side visit (no request) changes Inertia's own idea of the address too, so it does not put `?ask=` back.
        router.replace({ url: url.pathname + url.search + url.hash, props: (current) => current, preserveScroll: true, preserveState: true });
        ask(question);
    }, [isReady, ask]);
}

/** The empty thread: what the assistant does, and the repository's real starter questions as a list. */
function EmptyThread({ ask }: { ask: (question: string) => boolean }) {
    const { t } = useI18n();
    const starters = useStarterQuestions();

    return (
        <div className="max-w-2xl">
            <h2 className="font-display text-heading text-ink">{t('assistant.thread.emptyTitle')}</h2>
            <p className="mt-2 text-[0.9375rem] text-ink-muted">{t('assistant.thread.emptyText')}</p>
            {starters.length > 0 && (
                <ul aria-label={t('assistant.thread.starters')} className="mt-5 flex flex-col items-start gap-2">
                    {starters.map((question) => (
                        <li key={question} className="max-w-full">
                            <button
                                type="button"
                                onClick={() => ask(question)}
                                className="pressable cursor-pointer rounded-md bg-surface px-3 py-2 text-left text-sm text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                            >
                                {question}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <p className="mt-5 text-sm text-ink-subtle">{t('assistant.readyText')}</p>
        </div>
    );
}
