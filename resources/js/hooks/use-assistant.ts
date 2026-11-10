import { useHttp } from '@inertiajs/react';
import type { HttpResponse } from '@inertiajs/core';
import { useCallback, useRef, useState } from 'react';
import { countLabel } from '@/lib/format';
import type { AssistantReply } from '@/types';

/** Lungimile acceptate de server pentru o întrebare (AskAssistantRequest). */
export const MIN_QUESTION_LENGTH = 3;
export const MAX_QUESTION_LENGTH = 1000;

/** Câte mesaje anterioare trimitem cu fiecare întrebare, și cât de lung poate fi fiecare (limitele serverului). */
const MAX_HISTORY_MESSAGES = 6;
const MAX_HISTORY_CHARACTERS = 4000;

/** Un mesaj din conversație: întrebarea vizitatorului sau răspunsul asistentului. */
export type AssistantMessage =
    | { id: number; role: 'user'; content: string }
    | { id: number; role: 'assistant'; reply: AssistantReply };

type HistoryEntry = { role: 'user' | 'assistant'; content: string };

type Payload = { question: string; history: HistoryEntry[] };

/** O întrebare care nu a primit răspuns: mesajul de afișat și ce trimitem din nou la „Încearcă din nou”. */
type FailedQuestion = { message: string; payload: Payload };

/**
 * Conversația cu asistentul unui repository, ținută doar în componenta care folosește hook-ul
 * (nu se salvează nicăieri; la reîncărcarea paginii începe din nou).
 *
 * Fiecare întrebare pleacă cu useHttp (o cerere JSON obișnuită, nu o vizită Inertia, cu tokenul CSRF
 * din cookie-ul XSRF-TOKEN), împreună cu ultimele 6 mesaje, ca asistentul să înțeleagă întrebările
 * de continuare. Hook-ul nu desenează nimic: AssistantBar și AssistantThread îl primesc ca prop,
 * așa că pot fi puse oriunde (pagina „Asistent” sau bara de jos a oricărei pagini).
 */
export function useAssistant(repositoryId: number) {
    const http = useHttp<Payload, AssistantReply>({ question: '', history: [] });
    const [messages, setMessages] = useState<AssistantMessage[]>([]);
    const [failure, setFailure] = useState<FailedQuestion | null>(null);
    const nextId = useRef(1);

    const send = useCallback(
        (payload: Payload) => {
            setFailure(null);
            http.setData(payload);

            // Erorile ajung prin callback-uri; promisiunea respinsă nu mai are nimic de spus.
            http.post(`/repositories/${repositoryId}/assistant`, {
                onSuccess: (reply) => {
                    setMessages((current) => [...current, { id: nextId.current++, role: 'assistant', reply }]);
                },
                onError: (errors) => {
                    setFailure({ message: Object.values(errors)[0] ?? 'Întrebarea nu a putut fi trimisă.', payload });
                },
                onHttpException: (response) => {
                    setFailure({ message: messageForStatus(response), payload });
                },
                onNetworkError: () => {
                    setFailure({ message: 'Nu am putut contacta serverul. Verifică conexiunea și încearcă din nou.', payload });
                },
            }).catch(() => {});
        },
        [http, repositoryId],
    );

    /** Trimite o întrebare. Întoarce `false` când nu poate (prea scurtă sau un răspuns e încă în lucru). */
    const ask = useCallback(
        (question: string): boolean => {
            const trimmed = question.trim();

            if (trimmed.length < MIN_QUESTION_LENGTH || trimmed.length > MAX_QUESTION_LENGTH || http.processing) {
                return false;
            }

            const history = messages.slice(-MAX_HISTORY_MESSAGES).map(historyEntry);

            setMessages((current) => [...current, { id: nextId.current++, role: 'user', content: trimmed }]);
            send({ question: trimmed, history });

            return true;
        },
        [http.processing, messages, send],
    );

    /** Trimite din nou întrebarea care nu a primit răspuns. */
    const retry = useCallback(() => {
        if (failure !== null && !http.processing) {
            send(failure.payload);
        }
    }, [failure, http.processing, send]);

    /** Începe o conversație nouă. */
    const clear = useCallback(() => {
        http.cancel();
        setMessages([]);
        setFailure(null);
    }, [http]);

    return {
        messages,
        isAsking: http.processing,
        error: failure?.message ?? null,
        ask,
        retry,
        clear,
    };
}

/** Ce întoarce useAssistant: îl primesc AssistantBar și AssistantThread. */
export type Assistant = ReturnType<typeof useAssistant>;

/** Un mesaj în forma cerută de server pentru istoric. */
function historyEntry(message: AssistantMessage): HistoryEntry {
    if (message.role === 'user') {
        return { role: 'user', content: message.content };
    }

    return { role: 'assistant', content: message.reply.answer_markdown.slice(0, MAX_HISTORY_CHARACTERS) };
}

/** Mesajul pentru un răspuns cu eroare de la server, pe înțelesul oricui. */
function messageForStatus(response: HttpResponse): string {
    if (response.status === 429) {
        const seconds = Number(response.headers['retry-after']);
        const minutes = Number.isFinite(seconds) && seconds > 0 ? Math.ceil(seconds / 60) : null;

        return minutes === null
            ? 'Ai pus multe întrebări într-un timp scurt. Încearcă din nou puțin mai târziu.'
            : `Ai pus multe întrebări într-un timp scurt. Poți întreba din nou peste ${countLabel(minutes, 'minut', 'minute')}.`;
    }

    if (response.status === 419) {
        return 'Sesiunea a expirat. Reîncarcă pagina și întreabă din nou.';
    }

    if (response.status === 409) {
        return serverMessage(response) ?? 'Asistentul nu este pregătit încă pentru acest repository.';
    }

    return 'Asistentul nu a putut răspunde acum. Încearcă din nou.';
}

/** Mesajul trimis de server în JSON ({"message": "..."}), dacă există. */
function serverMessage(response: HttpResponse): string | null {
    try {
        const body: unknown = JSON.parse(response.data);

        return typeof body === 'object' && body !== null && 'message' in body && typeof body.message === 'string' ? body.message : null;
    } catch {
        return null;
    }
}
