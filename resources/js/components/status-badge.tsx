import type { RepositoryStatus } from '@/types';

/** Culorile etichetei pentru fiecare status. */
const badgeClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    cloning: 'bg-sky-50 text-sky-800 ring-sky-600/20',
    analyzing: 'bg-indigo-50 text-indigo-800 ring-indigo-600/20',
    documenting: 'bg-violet-50 text-violet-800 ring-violet-600/20',
    ready: 'bg-green-50 text-green-800 ring-green-600/20',
    updating: 'bg-sky-50 text-sky-800 ring-sky-600/20',
    failed: 'bg-red-50 text-red-800 ring-red-600/20',
};

/** Culoarea punctului din fața textului; cât timp o etapă lucrează, punctul pulsează ușor. */
const dotClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-amber-500',
    cloning: 'bg-sky-500 motion-safe:animate-pulse',
    analyzing: 'bg-indigo-500 motion-safe:animate-pulse',
    documenting: 'bg-violet-500 motion-safe:animate-pulse',
    ready: 'bg-green-500',
    updating: 'bg-sky-500 motion-safe:animate-pulse',
    failed: 'bg-red-500',
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
