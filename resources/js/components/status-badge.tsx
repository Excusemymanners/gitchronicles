import type { RepositoryStatus } from '@/types';

/** Culorile etichetei pentru fiecare status. */
const badgeClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    cloning: 'bg-sky-50 text-sky-800 ring-sky-600/20',
    cloned: 'bg-green-50 text-green-800 ring-green-600/20',
    failed: 'bg-red-50 text-red-800 ring-red-600/20',
};

/** Culoarea punctului din fața textului; la clonare punctul pulsează ușor. */
const dotClasses: Record<RepositoryStatus, string> = {
    pending: 'bg-amber-500',
    cloning: 'bg-sky-500 motion-safe:animate-pulse',
    cloned: 'bg-green-500',
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
