import { Head } from '@inertiajs/react';
import { useAssistantConversation } from '@/components/assistant/assistant-provider';
import AssistantThread from '@/components/assistant/assistant-thread';
import RepositoryLayout from '@/layouts/repository-layout';
import { shortSha } from '@/lib/format';
import type { RepositoryPageProps } from '@/types';

/**
 * Pagina „Asistent”: aceeași conversație ca în bara de jos a spațiului de lucru (AssistantProvider),
 * pe toată înălțimea capitolului, ca un răspuns lung să se citească ușor. Întrebările se pun tot din
 * bara de jos (AssistantDock), care pe pagina asta nu mai deschide panoul ei.
 */
export default function RepositoriesAssistant({ repository, workspace }: RepositoryPageProps) {
    const conversation = useAssistantConversation();
    const hasConversation = conversation.messages.length > 0;

    return (
        <RepositoryLayout>
            <Head title={`Asistent · ${repository.full_name}`} />

            <div className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 sm:flex-1">
                    <h1 className="text-2xl font-semibold tracking-tight text-ink">Asistent</h1>
                    <p className="mt-1 text-sm text-ink-muted">
                        Răspunde doar din codul repository-ului
                        {repository.last_sha && (
                            <>
                                {' '}
                                (commitul <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(repository.last_sha)}</code>)
                            </>
                        )}{' '}
                        și trimite la fișierele și liniile exacte pe GitHub.
                    </p>
                </div>

                {hasConversation && (
                    <button
                        type="button"
                        onClick={conversation.clear}
                        className="shrink-0 cursor-pointer self-start rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        Conversație nouă
                    </button>
                )}
            </div>

            <div className="mt-6">
                {hasConversation ? (
                    <AssistantThread assistant={conversation} />
                ) : (
                    <div className="rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                        <p className="font-medium text-ink">
                            {workspace.assistant.is_indexed ? 'Pune o întrebare în bara de jos' : 'Asistentul nu este gata încă'}
                        </p>
                        <p className="mt-2">
                            {workspace.assistant.is_indexed
                                ? 'Caut cele mai potrivite fragmente de cod și răspund pe baza lor. Fiecare răspuns arată sursele, cu legături spre liniile citate.'
                                : 'După ce codul este indexat, poți întreba orice despre el din bara de jos. Fiecare răspuns va arăta sursele, cu legături spre liniile citate.'}
                        </p>
                    </div>
                )}
            </div>
        </RepositoryLayout>
    );
}
