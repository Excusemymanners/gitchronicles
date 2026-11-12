import type en from '@/lang/en';
import type { RepositoryStatus } from '@/types';

/**
 * Un pas al procesării unui repository. Pașii sunt cei din ProcessRepository::STAGES (Laravel), în ordinea
 * în care rulează; `during` e statusul pe care îl are repository-ul cât timp rulează pasul.
 * Numele și descrierea pasului sunt în dicționare, la „pipeline.stages.{key}” (label, description).
 */
export type PipelineStage = {
    key: keyof typeof en.pipeline.stages;
    during: RepositoryStatus;
};

export const PIPELINE_STAGES: PipelineStage[] = [
    {
        key: 'clone',
        during: 'cloning',
    },
    {
        key: 'analyze',
        during: 'analyzing',
    },
    {
        key: 'history',
        during: 'analyzing',
    },
    {
        key: 'docs',
        during: 'documenting',
    },
    {
        key: 'diagrams',
        during: 'documenting',
    },
    {
        key: 'index',
        during: 'documenting',
    },
    {
        key: 'ready',
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
