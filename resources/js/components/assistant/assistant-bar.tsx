import { useId, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { ArrowUpIcon } from '@/components/workspace/icons';
import { MAX_QUESTION_LENGTH, MIN_QUESTION_LENGTH } from '@/hooks/use-assistant';
import type { Assistant } from '@/hooks/use-assistant';
import type { RepositoryItem } from '@/types';

type Props = {
    assistant: Assistant;
    repository: RepositoryItem;
    /** Adevărat după ce codul a fost indexat (workspace.assistant.is_indexed). */
    isIndexed: boolean;
    /** Întrebările de pornire despre module reale (workspace.assistant.starters). */
    starters: string[];
};

/** De la câte caractere arătăm cât mai rămâne până la limită. */
const COUNTER_FROM = MAX_QUESTION_LENGTH - 100;

/** De ce nu se pot pune încă întrebări, sau `null` când asistentul e gata. */
export function assistantUnavailableReason(repository: RepositoryItem, isIndexed: boolean): string | null {
    if (isIndexed) {
        return null;
    }

    if (repository.is_processing) {
        return 'Asistentul pornește după ce codul este analizat și indexat. Procesarea e în curs.';
    }

    if (repository.status === 'failed') {
        return 'Procesarea a eșuat, așa că asistentul nu are cod în care să caute.';
    }

    return 'Codul acestui repository nu a fost indexat încă pentru asistent.';
}

/**
 * Bara de întrebări a asistentului (în AssistantDock, jos pe orice pagină a repository-ului):
 * întrebările de pornire (cât timp nu există o conversație), câmpul de text — Enter trimite,
 * Shift+Enter face rând nou — și un rând de explicație. Când asistentul nu e gata, câmpul e dezactivat
 * și rândul de explicație spune de ce.
 */
export default function AssistantBar({ assistant, repository, isIndexed, starters }: Props) {
    const [question, setQuestion] = useState('');
    const [hint, setHint] = useState<string | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const inputId = useId();
    const hintId = useId();

    const unavailableReason = assistantUnavailableReason(repository, isIndexed);
    const isDisabled = unavailableReason !== null;
    const canSend = !isDisabled && !assistant.isAsking && question.trim().length >= MIN_QUESTION_LENGTH;
    const showStarters = !isDisabled && assistant.messages.length === 0 && starters.length > 0;

    function submit(text: string) {
        if (text.trim().length < MIN_QUESTION_LENGTH) {
            setHint(`Întrebarea trebuie să aibă cel puțin ${MIN_QUESTION_LENGTH} caractere.`);

            return;
        }

        if (assistant.ask(text)) {
            setQuestion('');
            setHint(null);
            textareaRef.current?.focus();
        }
    }

    function handleSubmit(event: FormEvent) {
        event.preventDefault();
        submit(question);
    }

    function handleKeyDown(event: KeyboardEvent<HTMLTextAreaElement>) {
        // Enter trimite; Shift+Enter (și Enter în timpul compunerii unui caracter, IME) rămâne rând nou.
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            submit(question);
        }
    }

    return (
        <div>
            {showStarters && (
                // Pe telefon butoanele rămân pe un rând care se derulează orizontal, ca bara să nu crească.
                <ul aria-label="Întrebări de pornire" className="-mx-3 mb-2.5 flex gap-2 overflow-x-auto px-3 pb-0.5 [scrollbar-width:none] sm:mx-0 sm:flex-wrap sm:px-0">
                    {starters.map((starter) => (
                        <li key={starter} className="shrink-0">
                            <button
                                type="button"
                                onClick={() => submit(starter)}
                                disabled={assistant.isAsking}
                                className="cursor-pointer rounded-full border border-line bg-surface-raised px-3 py-1 text-xs whitespace-nowrap text-ink-muted hover:border-line-strong hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {starter}
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            <form
                onSubmit={handleSubmit}
                className="flex items-end gap-2 rounded-2xl border border-line-strong bg-surface-raised py-1.5 pr-1.5 pl-4 shadow-raised has-[textarea:focus-visible]:outline-2 has-[textarea:focus-visible]:-outline-offset-1 has-[textarea:focus-visible]:outline-accent"
            >
                <label htmlFor={inputId} className="sr-only">
                    Întrebare despre {repository.full_name}
                </label>
                <textarea
                    id={inputId}
                    ref={textareaRef}
                    value={question}
                    onChange={(event) => {
                        setQuestion(event.target.value);
                        setHint(null);
                    }}
                    onKeyDown={handleKeyDown}
                    disabled={isDisabled}
                    maxLength={MAX_QUESTION_LENGTH}
                    rows={1}
                    aria-describedby={hintId}
                    placeholder={`Întreabă orice despre ${repository.full_name}…`}
                    className="field-sizing-content max-h-32 min-h-9 min-w-0 flex-1 resize-none bg-transparent py-2 text-[0.9375rem] text-ink placeholder:text-ink-subtle focus:outline-none disabled:cursor-not-allowed"
                />
                <button
                    type="submit"
                    disabled={!canSend}
                    aria-label={assistant.isAsking ? 'Se scrie răspunsul' : 'Trimite întrebarea'}
                    className="grid size-9 shrink-0 cursor-pointer place-items-center rounded-xl bg-accent-strong text-on-accent hover:bg-accent-strong-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <ArrowUpIcon />
                </button>
            </form>

            <p id={hintId} className={`mt-2 text-center text-[0.6875rem] sm:text-xs ${hint ? 'text-danger' : 'text-ink-subtle'}`}>
                {unavailableReason ?? hint ?? (
                    <>
                        Răspunde doar din cod și arată fișierele și liniile folosite.
                        <span className="max-sm:hidden"> Enter trimite, Shift+Enter face rând nou.</span>
                    </>
                )}
                {!isDisabled && question.length >= COUNTER_FROM && ` ${question.length}/${MAX_QUESTION_LENGTH}`}
            </p>
        </div>
    );
}
