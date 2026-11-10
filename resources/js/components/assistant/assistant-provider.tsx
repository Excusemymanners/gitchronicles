import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import { useAssistant } from '@/hooks/use-assistant';
import type { Assistant } from '@/hooks/use-assistant';

const AssistantContext = createContext<Assistant | null>(null);

type Props = {
    repositoryId: number;
    children: ReactNode;
};

/**
 * Ține o singură conversație cu asistentul pentru tot spațiul de lucru al unui repository: bara de jos
 * (AssistantDock) și pagina „Asistent” o citesc pe aceeași, deci conversația rămâne când deschizi alt
 * capitol. WorkspaceLayout îl pune sub cheia repository-ului, așa că alt repository începe de la zero.
 */
export default function AssistantProvider({ repositoryId, children }: Props) {
    const assistant = useAssistant(repositoryId);

    return <AssistantContext value={assistant}>{children}</AssistantContext>;
}

/** Conversația din AssistantProvider; o componentă din afara lui e o greșeală de programare. */
export function useAssistantConversation(): Assistant {
    const assistant = useContext(AssistantContext);

    if (assistant === null) {
        throw new Error('useAssistantConversation() needs an <AssistantProvider> above it.');
    }

    return assistant;
}
