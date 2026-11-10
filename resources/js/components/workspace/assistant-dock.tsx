import { useId } from 'react';
import type { ReactNode } from 'react';
import { ArrowUpIcon } from '@/components/workspace/icons';
import type { RepositoryItem, Workspace } from '@/types';

type Props = {
    repository: RepositoryItem;
    assistant: Workspace['assistant'];
    /**
     * Răspunsurile, afișate într-un panou deasupra barei (AssistantThread din Faza 8).
     * Lipsește cât timp nu s-a pus nicio întrebare.
     */
    thread?: ReactNode;
};

/**
 * Bara asistentului, mereu jos, peste conținutul capitolului (ca în NotebookLM): butoane cu întrebări
 * de pornire despre module reale, câmpul „Întreabă orice despre …” și un rând de explicație.
 * Un gradient spre culoarea fundalului face ca textul de dedesubt să dispară frumos sub bară.
 *
 * Asistentul se construiește în Faza 8, așa că deocamdată bara e dezactivată și spune când apare.
 * La integrare, câmpul și butoanele primesc useAssistant / AssistantBar, iar `thread` primește răspunsurile.
 */
export default function AssistantDock({ repository, assistant, thread }: Props) {
    const inputId = useId();
    const hintId = useId();

    return (
        <section
            aria-label="Asistent"
            className="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-linear-to-b from-canvas/0 via-canvas/90 to-canvas px-3 pt-10 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:px-8"
        >
            <div className="pointer-events-auto mx-auto max-w-190">
                {thread && (
                    <div className="mb-3 max-h-[min(28rem,55dvh)] overflow-y-auto rounded-2xl border border-line bg-surface-raised shadow-raised">
                        {thread}
                    </div>
                )}

                {assistant.starters.length > 0 && (
                    // Pe telefon butoanele rămân pe un rând care se derulează orizontal, ca bara să nu crească.
                    <ul aria-label="Întrebări de pornire" className="-mx-3 mb-2.5 flex gap-2 overflow-x-auto px-3 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0">
                        {assistant.starters.map((question) => (
                            <li key={question} className="shrink-0">
                                <button
                                    type="button"
                                    disabled
                                    className="rounded-full border border-line bg-surface-raised px-3 py-1 text-xs whitespace-nowrap text-ink-muted disabled:cursor-not-allowed"
                                >
                                    {question}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                <form
                    onSubmit={(event) => event.preventDefault()}
                    className="flex items-center gap-2 rounded-2xl border border-line-strong bg-surface-raised py-1.5 pr-1.5 pl-4 shadow-raised has-[input:focus-visible]:outline-2 has-[input:focus-visible]:-outline-offset-1 has-[input:focus-visible]:outline-accent"
                >
                    <label htmlFor={inputId} className="sr-only">
                        Întrebare despre {repository.full_name}
                    </label>
                    <input
                        id={inputId}
                        type="text"
                        disabled
                        aria-describedby={hintId}
                        placeholder={`Întreabă orice despre ${repository.full_name}…`}
                        className="min-w-0 flex-1 truncate bg-transparent py-2 text-[0.9375rem] text-ink placeholder:text-ink-subtle focus:outline-none disabled:cursor-not-allowed"
                    />
                    <button
                        type="submit"
                        disabled
                        aria-label="Trimite întrebarea"
                        className="grid size-9 shrink-0 place-items-center rounded-xl bg-accent-strong text-on-accent disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <ArrowUpIcon />
                    </button>
                </form>

                <p id={hintId} className="mt-2 text-center text-[0.6875rem] text-ink-subtle sm:text-xs">
                    Asistentul apare imediat ce codul e indexat.
                    <span className="max-sm:hidden"> Va răspunde din cod și va arăta fișierele și liniile folosite.</span>
                </p>
            </div>
        </section>
    );
}
