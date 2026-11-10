import type { MediaStatus } from '@/types/media';

/*
 * Aceleași culori ca la statusul unui repository (StatusBadge): chihlimbar = așteaptă în coadă,
 * albastru = se lucrează (punctul pulsează), verde = gata, roșu = a eșuat.
 */

const badgeClasses: Record<MediaStatus, string> = {
    pending: 'bg-warning-soft text-warning-ink ring-warning-line',
    running: 'bg-info-soft text-info-ink ring-info-line',
    ready: 'bg-success-soft text-success-ink ring-success-line',
    failed: 'bg-danger-soft text-danger-ink ring-danger-line',
};

const dotClasses: Record<MediaStatus, string> = {
    pending: 'bg-warning',
    running: 'bg-info motion-safe:animate-pulse',
    ready: 'bg-success',
    failed: 'bg-danger',
};

type Props = {
    status: MediaStatus;
    /** Textul afișat (status_label de la server). */
    label: string;
};

/** Eticheta rotunjită cu starea video-ului sau a slide-urilor. */
export default function MediaStatusBadge({ status, label }: Props) {
    return (
        <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset ${badgeClasses[status]}`}>
            <span aria-hidden="true" className={`size-1.5 rounded-full ${dotClasses[status]}`} />
            {label}
        </span>
    );
}
