import { Link } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import AssistantBar from '@/components/assistant/assistant-bar';
import { useAssistantConversation } from '@/components/assistant/assistant-provider';
import AssistantThread from '@/components/assistant/assistant-thread';
import { countLabel } from '@/lib/format';
import type { RepositoryItem, Workspace } from '@/types';

type Props = {
    repository: RepositoryItem;
    assistant: Workspace['assistant'];
    /** Fals pe pagina „Asistent”, care arată ea conversația pe toată înălțimea. */
    showThread?: boolean;
};

const panelButtonClasses =
    'cursor-pointer rounded-md px-2 py-1 text-xs font-medium text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Bara asistentului, mereu jos, peste conținutul capitolului (ca în NotebookLM): întrebările de pornire
 * despre module reale, câmpul „Întreabă orice despre …” și un rând de explicație (AssistantBar).
 * Un gradient spre culoarea fundalului face ca textul de dedesubt să dispară frumos sub bară.
 *
 * Deasupra barei, conversația (AssistantThread) stă într-un panou care se derulează singur; panoul se
 * poate ascunde, se redeschide la o întrebare nouă și duce la pagina „Asistent” pentru citit pe larg.
 * Conversația vine din AssistantProvider, deci rămâne aceeași când deschizi alt capitol.
 */
export default function AssistantDock({ repository, assistant, showThread = true }: Props) {
    const conversation = useAssistantConversation();
    const [isThreadOpen, setThreadOpen] = useState(true);
    const hasConversation = conversation.messages.length > 0;

    // O întrebare nouă redeschide panoul, ca răspunsul să se vadă.
    useEffect(() => {
        setThreadOpen(true);
    }, [conversation.messages.length]);

    return (
        <section
            aria-label="Asistent"
            className="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-linear-to-b from-canvas/0 via-canvas/90 to-canvas px-3 pt-10 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-8"
        >
            <div className="pointer-events-auto mx-auto max-w-190">
                {showThread && hasConversation && isThreadOpen && (
                    <div className="mb-3 flex max-h-[min(28rem,55dvh)] flex-col overflow-hidden rounded-2xl border border-line bg-surface-raised shadow-raised">
                        <div className="flex items-center justify-between gap-2 border-b border-line px-4 py-1.5">
                            <p className="text-xs font-semibold text-ink-muted">Conversația cu asistentul</p>
                            <div className="flex flex-wrap justify-end gap-1">
                                <Link href={`/repositories/${repository.id}/assistant`} className={panelButtonClasses}>
                                    Pe toată pagina
                                </Link>
                                <button type="button" onClick={conversation.clear} className={panelButtonClasses}>
                                    Conversație nouă
                                </button>
                                <button type="button" onClick={() => setThreadOpen(false)} className={panelButtonClasses}>
                                    Ascunde
                                </button>
                            </div>
                        </div>
                        <div className="relative overflow-y-auto overscroll-contain px-4 py-4">
                            <AssistantThread assistant={conversation} />
                        </div>
                    </div>
                )}

                {showThread && hasConversation && !isThreadOpen && (
                    <button
                        type="button"
                        onClick={() => setThreadOpen(true)}
                        className="mb-2 cursor-pointer rounded-full border border-line bg-surface-raised px-3 py-1 text-xs font-medium text-ink-muted shadow-raised hover:border-line-strong hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                    >
                        Arată conversația ({countLabel(conversation.messages.length, 'mesaj', 'mesaje')})
                    </button>
                )}

                <AssistantBar assistant={conversation} repository={repository} isIndexed={assistant.is_indexed} starters={assistant.starters} />
            </div>
        </section>
    );
}
