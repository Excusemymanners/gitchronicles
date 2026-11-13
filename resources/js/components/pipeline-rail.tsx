import type { CSSProperties } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { PIPELINE_STAGES, stageStates } from '@/lib/pipeline';
import type { StageState } from '@/lib/pipeline';
import type { RepositoryStatus } from '@/types';

/*
 * „Linia”: motivul din logo (o linie cu inele de commit care se termină într-un cadran de ceas), folosit
 * pentru orice e o succesiune de pași. Aici arată pașii reali ai procesării unui repository:
 *  - fără `status`, e explicația „Cum funcționează”: toți pașii, în ordine;
 *  - cu `status`, e progresul real al unui repository: pașii făcuți, cei în lucru (pulsează) și cei rămași.
 */

type Props = {
    /** Statusul repository-ului; lipsește când linia doar explică pașii. */
    status?: RepositoryStatus;
    /** Cu descrierea fiecărui pas (explicație) sau doar inelele, pe un rând (progres compact). */
    variant?: 'detailed' | 'compact';
    /**
     * Linia „se scrie” o dată, la încărcarea paginii, ca de o peniță: pornește de la primul inel, se oprește la
     * fiecare inel (care se așază când linia ajunge la el), iar la capăt cadranul se desenează în jurul ultimului.
     */
    animated?: boolean;
    className?: string;
};

/** Inelul unui pas: gol ca în logo; plin cât se lucrează la el. */
const ringClasses: Record<StageState, string> = {
    done: 'border-ink bg-canvas',
    active: 'border-accent bg-accent',
    todo: 'border-line-strong bg-canvas',
};

/** Pasul unui element pe linia care se scrie, pentru clasa „beat” (vezi app.css). */
function beatOf(index: number): CSSProperties {
    return { '--beat': index } as CSSProperties;
}

export default function PipelineRail({ status, variant = 'detailed', animated = false, className = '' }: Props) {
    const { t } = useI18n();

    // Fără status, toți pașii arată la fel: sunt o explicație, nu un progres.
    const states: StageState[] = status ? stageStates(status) : PIPELINE_STAGES.map(() => 'done');

    if (variant === 'compact') {
        return <CompactRail states={states} className={className} />;
    }

    return (
        <ol className={`flex flex-col ${className}`}>
            {PIPELINE_STAGES.map((stage, index) => {
                const state = states[index];
                const isLast = index === PIPELINE_STAGES.length - 1;

                return (
                    <li key={stage.key} className="relative flex gap-4 pb-6 last:pb-0">
                        {/* Segmentul de linie până la pasul următor; ultimul pas nu are. */}
                        {!isLast && (
                            <span
                                aria-hidden="true"
                                style={beatOf(index)}
                                className={`absolute top-5 bottom-0 left-[0.5625rem] w-0.5 origin-top rounded-full ${
                                    states[index + 1] === 'todo' ? 'bg-line-strong' : 'bg-ink/50'
                                } ${animated ? 'animate-trace beat' : ''}`}
                            />
                        )}

                        <span
                            aria-hidden="true"
                            style={beatOf(index)}
                            className={`relative mt-0.5 flex size-5 shrink-0 items-center justify-center ${animated && !isLast ? 'animate-pop beat' : ''}`}
                        >
                            {state === 'active' && <span className="absolute size-3.5 rounded-full bg-accent animate-halo" />}
                            {isLast ? <DialRing state={state} beat={animated ? index : undefined} /> : <span className={`relative size-3.5 rounded-full border-2 ${ringClasses[state]}`} />}
                        </span>

                        <div
                            style={beatOf(index)}
                            className={`min-w-0 ${animated ? 'animate-fade-in beat' : ''}`}
                            // Cititoarele de ecran află și starea pasului, nu doar culoarea inelului.
                            aria-current={state === 'active' ? 'step' : undefined}
                        >
                            <p className={`text-sm font-medium ${state === 'todo' ? 'text-ink-muted' : 'text-ink'}`}>
                                {t(`pipeline.stages.${stage.key}.label`)}
                                {status && state === 'done' && <span className="sr-only"> {t('pipeline.done')}</span>}
                                {status && state === 'active' && <span className="sr-only"> {t('pipeline.inProgress')}</span>}
                            </p>
                            <p className="mt-0.5 text-[0.8125rem] leading-5 text-ink-muted">{t(`pipeline.stages.${stage.key}.description`)}</p>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}

/**
 * Ultimul pas, „Gata”, e desenat ca în logo: inelul stă într-un cadran de ceas, deschis spre dreapta, cu trei
 * gradații. De aici documentația merge mai departe în timp, commit cu commit. Desenul e mai mare decât locul
 * inelului (28 px într-un loc de 20 px), așa că e centrat peste el.
 * Cu `beat` (linia se scrie), inelul se așază la pasul lui, apoi cadranul se desenează în jurul lui, pornind
 * de sus, de unde vine linia, iar gradațiile apar la urmă.
 */
function DialRing({ state, beat }: { state: StageState; beat?: number }) {
    const stroke = state === 'todo' ? 'stroke-line-strong' : state === 'active' ? 'stroke-accent' : 'stroke-ink';
    const animated = beat !== undefined;
    const timing = animated ? beatOf(beat) : undefined;

    return (
        <svg viewBox="0 0 28 28" fill="none" strokeLinecap="round" className={`absolute size-7 max-w-none ${stroke}`}>
            <circle
                cx="14"
                cy="14"
                r="6"
                strokeWidth={2}
                style={timing}
                className={`origin-center [transform-box:fill-box] ${state === 'active' ? 'fill-accent' : 'fill-canvas'} ${animated ? 'animate-pop beat' : ''}`}
            />
            <path
                d="M18.4 2.9A12 12 0 1 0 18.4 25.1"
                pathLength={1}
                strokeWidth={1.5}
                style={animated ? { ...timing, animationDuration: 'calc(var(--motion-beat) * 4)' } : undefined}
                className={`opacity-60 ${animated ? 'animate-draw beat' : ''}`}
            />
            <path
                d="M22.4 6.6l1.2-1.1M24.6 14h1.7M22.4 21.4l1.2 1.1"
                strokeWidth={1.5}
                style={animated ? { '--beat': beat + 4 } as CSSProperties : undefined}
                className={`opacity-60 ${animated ? 'animate-fade-in beat' : ''}`}
            />
        </svg>
    );
}

/** Varianta pe un rând: doar inelele și linia dintre ele, pentru un card. Eticheta pasului o scrie cardul. */
function CompactRail({ states, className }: { states: StageState[]; className: string }) {
    return (
        <span aria-hidden="true" className={`flex items-center ${className}`}>
            {states.map((state, index) => (
                <span key={PIPELINE_STAGES[index].key} className="flex flex-1 items-center last:flex-none">
                    <span className="relative flex size-3 shrink-0 items-center justify-center">
                        {state === 'active' && <span className="absolute size-2.5 rounded-full bg-accent animate-halo" />}
                        <span className={`relative size-2.5 rounded-full border-2 ${ringClasses[state]}`} />
                    </span>
                    {index < states.length - 1 && (
                        <span className={`h-0.5 flex-1 ${states[index + 1] === 'todo' ? 'bg-line-strong' : 'bg-ink/50'}`} />
                    )}
                </span>
            ))}
        </span>
    );
}
