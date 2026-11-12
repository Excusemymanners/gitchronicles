import { Head } from '@inertiajs/react';
import { useAssistantConversation } from '@/components/assistant/assistant-provider';
import AssistantThread from '@/components/assistant/assistant-thread';
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
                ) : !workspace.assistant.is_indexed && repository.status === 'ready' ? (
                    <NotIndexed completeness={completeness} />
                ) : (
                    <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        <p className="font-medium text-ink">
                            {workspace.assistant.is_indexed ? t('assistant.readyTitle') : t('assistant.waitingTitle')}
                        </p>
                        <p className="mt-2">
                            {workspace.assistant.is_indexed ? t('assistant.readyText') : t('assistant.waitingText')}
                        </p>
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
