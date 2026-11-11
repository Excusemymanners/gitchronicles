import type { RepositoryItem } from '@/types';

/** Un limbaj al unui repository și partea lui din toate liniile de cod (între 0 și 1). */
export type LanguageShare = {
    name: string;
    share: number;
};

/**
 * Ce știm, din date reale, despre un repository procesat (RepositoryCardResource în Laravel).
 * Totul e 0, fals sau gol până când etapa care produce datele s-a terminat.
 */
export type RepositoryFacts = {
    file_count: number;
    line_count: number;
    module_count: number;
    commit_count: number;
    /** Paginile de documentație scrise: „Despre proiect”, README-ul și paginile modulelor. */
    document_count: number;
    diagram_count: number;
    /** Adevărat după ce codul a fost indexat pentru asistent. */
    has_assistant: boolean;
    /** Adevărat când video-ul de prezentare e gata. */
    has_video: boolean;
    /** Primele trei limbaje după numărul de linii; restul liniilor sunt „Altele”. */
    languages: LanguageShare[];
    /** Când s-a încheiat ultima procesare reușită (ISO 8601); `null` dacă nu a reușit niciuna. */
    processed_at: string | null;
};

/** Un repository pe prima pagină: datele lui obișnuite plus faptele afișate pe card. */
export type RepositoryCard = RepositoryItem & {
    facts: RepositoryFacts;
};
