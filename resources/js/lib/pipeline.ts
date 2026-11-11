import type { RepositoryStatus } from '@/types';

/**
 * Un pas al procesării unui repository. Pașii sunt cei din ProcessRepository::STAGES (Laravel), în ordinea
 * în care rulează; `during` e statusul pe care îl are repository-ul cât timp rulează pasul.
 */
export type PipelineStage = {
    key: string;
    label: string;
    description: string;
    during: RepositoryStatus;
};

export const PIPELINE_STAGES: PipelineStage[] = [
    {
        key: 'clone',
        label: 'Clonare',
        description: 'Aducem repository-ul de pe GitHub, cu tot istoricul lui.',
        during: 'cloning',
    },
    {
        key: 'analyze',
        label: 'Analiza codului',
        description: 'Citim fiecare fișier: limbaje, module, clase și funcții, cine importă pe cine.',
        during: 'analyzing',
    },
    {
        key: 'history',
        label: 'Istoricul git',
        description: 'Importăm commit-urile: autori, fișiere atinse, linii adăugate și șterse.',
        during: 'analyzing',
    },
    {
        key: 'docs',
        label: 'Documentație',
        description: 'Scriem „Despre proiect”, câte o pagină pentru modulele principale și un README.',
        during: 'documenting',
    },
    {
        key: 'diagrams',
        label: 'Diagrame',
        description: 'Desenăm arhitectura: straturi, C4 și fluxurile principale.',
        during: 'documenting',
    },
    {
        key: 'index',
        label: 'Index pentru asistent',
        description: 'Împărțim codul în fragmente căutabile, ca răspunsurile să citeze linii exacte.',
        during: 'documenting',
    },
    {
        key: 'ready',
        label: 'Gata',
        description: 'La un commit nou refacem doar ce s-a schimbat, nu tot proiectul.',
        during: 'ready',
    },
];

/** Starea unui pas pe linie: făcut, în lucru sau încă neînceput. */
export type StageState = 'done' | 'active' | 'todo';

/** Ordinea statusurilor prin care trece un repository la prima procesare. */
const statusOrder: Partial<Record<RepositoryStatus, number>> = {
    pending: 0,
    cloning: 1,
    analyzing: 2,
    documenting: 3,
    ready: 4,
};

/**
 * Starea fiecărui pas pentru un status dat. Un status acoperă uneori mai mulți pași („se analizează codul”
 * înseamnă analiza și importul istoricului), iar atunci toți pașii lui apar „în lucru”: nu știm mai precis.
 * Un repository gata sau în curs de actualizare are toți pașii făcuți; unul eșuat nu ajunge aici.
 */
export function stageStates(status: RepositoryStatus): StageState[] {
    const current = statusOrder[status] ?? statusOrder.ready!;

    return PIPELINE_STAGES.map((stage) => {
        const position = statusOrder[stage.during]!;

        if (current === statusOrder.ready || position < current) {
            return 'done';
        }

        return position === current ? 'active' : 'todo';
    });
}
