import { useI18n } from '@/hooks/use-i18n';
import type { Severity } from '@/types/consulting';

/*
 * Gravitatea unei probleme nu e spusă doar prin culoare: fiecare treaptă are și o formă proprie
 * (octogon, triunghi, romb, cerc, cerc gol) și eticheta scrisă. Culorile sunt tonurile de stare:
 * roșu pentru critică și ridicată, chihlimbar pentru medie, albastru pentru scăzută, neutru pentru informativă.
 */

const badgeClasses: Record<Severity, string> = {
    critical: 'bg-danger-soft text-danger-ink ring-danger-line',
    high: 'bg-danger-soft text-danger-ink ring-danger-line',
    medium: 'bg-warning-soft text-warning-ink ring-warning-line',
    low: 'bg-info-soft text-info-ink ring-info-line',
    info: 'bg-surface-2 text-ink-muted ring-line',
};

/** Forma fiecărei trepte, pe o grilă de 16. */
const shapes: Record<Severity, string> = {
    critical: 'M5.5 1.5h5l4 4v5l-4 4h-5l-4-4v-5Z',
    high: 'M8 1.5 15 14.5H1Z',
    medium: 'M8 1.5 14.5 8 8 14.5 1.5 8Z',
    low: 'M8 2a6 6 0 1 0 0 12A6 6 0 0 0 8 2Z',
    info: 'M8 3a5 5 0 1 0 0 10A5 5 0 0 0 8 3Z',
};

/** Iconița unei trepte de gravitate: pline pentru cele care contează în scor, goală pentru „informativă”. */
export function SeverityIcon({ severity, className = 'size-3' }: { severity: Severity; className?: string }) {
    return (
        <svg aria-hidden="true" viewBox="0 0 16 16" className={`shrink-0 ${className}`}>
            <path
                d={shapes[severity]}
                fill={severity === 'info' ? 'none' : 'currentColor'}
                stroke="currentColor"
                strokeWidth={severity === 'info' ? 1.75 : 0}
                strokeLinejoin="round"
            />
        </svg>
    );
}

type Props = {
    severity: Severity;
    /** Textul din etichetă; fără el, numele treptei în limba interfeței (de exemplu „High”). */
    label?: string;
};

/** Eticheta rotunjită cu gravitatea unei probleme: forma, culoarea și cuvântul. */
export default function SeverityBadge({ severity, label }: Props) {
    const { t } = useI18n();

    return (
        <span className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${badgeClasses[severity]}`}>
            <SeverityIcon severity={severity} />
            {label ?? t(`severities.${severity}`)}
        </span>
    );
}

/** Treptele, de la cea mai gravă. */
export const SEVERITIES: Severity[] = ['critical', 'high', 'medium', 'low', 'info'];
