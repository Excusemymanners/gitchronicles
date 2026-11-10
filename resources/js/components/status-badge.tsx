import type { RepositoryStatus } from '@/types';

/*
 * Culoarea spune în ce grup e statusul, textul spune etapa exactă:
 * chihlimbar = așteaptă, albastru = se lucrează (clonare, analiză, documentare, actualizare),
 * verde = gata, roșu = a eșuat. Culorile sunt tokenii de stare din resources/css/app.css.
 */

/** Fundalul, textul și chenarul etichetei pentru fiecare status. */
const badgeClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-warning-soft text-warning-ink ring-warning-line',
    cloning: 'bg-info-soft text-info-ink ring-info-line',
    analyzing: 'bg-info-soft text-info-ink ring-info-line',
    documenting: 'bg-info-soft text-info-ink ring-info-line',
    ready: 'bg-success-soft text-success-ink ring-success-line',
    updating: 'bg-info-soft text-info-ink ring-info-line',
    failed: 'bg-danger-soft text-danger-ink ring-danger-line',
};

/** Culoarea punctului din fața textului; cât timp o etapă lucrează, punctul pulsează ușor. */
const dotClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-warning',
    cloning: 'bg-info motion-safe:animate-pulse',
    analyzing: 'bg-info motion-safe:animate-pulse',
    documenting: 'bg-info motion-safe:animate-pulse',
    ready: 'bg-success',
    updating: 'bg-info motion-safe:animate-pulse',
    failed: 'bg-danger',
};

type Props = {
    status: RepositoryStatus;
    /** Textul afișat (status_label de la server). */
    label: string;
};

/** Eticheta rotunjită cu statusul unui repository, colorată după status. */
export default function StatusBadge({ status, label }: Props) {
    return (
        <span
            className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${badgeClasses[status]}`}
        >
            <span aria-hidden="true" className={`size-1.5 rounded-full ${dotClasses[status]}`} />
            {label}
        </span>
    );
}
