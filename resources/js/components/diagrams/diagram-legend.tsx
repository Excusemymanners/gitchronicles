import { useI18n } from '@/hooks/use-i18n';
import { DARK_CLASS_STYLES, LIGHT_CLASS_STYLES, useDiagramTheme } from '@/lib/mermaid';

/** Ce poate apărea într-o legendă: o clasă de stil a diagramelor sau săgeata. */
export type LegendItem = {
    label: string;
    /** O clasă din LIGHT_CLASS_STYLES (person, containerBox, component, ...) sau „arrow”. */
    swatch: string;
};

type Props = {
    items: LegendItem[];
};

/**
 * Legenda mică de sub o diagramă: un pătrățel cu aceleași culori ca în diagramă (în tema afișată)
 * și ce înseamnă.
 */
export default function DiagramLegend({ items }: Props) {
    const { t } = useI18n();
    const theme = useDiagramTheme();

    return (
        <ul aria-label={t('diagrams.legend.label')} className="flex flex-wrap gap-x-4 gap-y-1 px-1 text-[0.8125rem] text-ink-muted">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-1.5">
                    <Swatch name={item.swatch} style={theme === 'dark' ? (DARK_CLASS_STYLES[item.swatch] ?? LIGHT_CLASS_STYLES[item.swatch]) : LIGHT_CLASS_STYLES[item.swatch]} />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

/**
 * Pătrățelul unei clase: culorile se citesc din stilul Mermaid al clasei („fill:#…,stroke:#…”).
 * Sunt atribute SVG, ca în diagramă, nu clase Tailwind: sunt culorile diagramei, nu ale paginii.
 */
function Swatch({ name, style }: { name: string; style: string | undefined }) {
    if (name === 'arrow') {
        return (
            <svg aria-hidden="true" width="22" height="12" viewBox="0 0 22 12" fill="none" className="shrink-0 text-ink-subtle">
                <path d="M1 6h16" stroke="currentColor" strokeWidth="1.5" />
                <path d="M15 2l5 4-5 4z" fill="currentColor" />
            </svg>
        );
    }

    const values = Object.fromEntries((style ?? '').split(',').map((part) => part.split(':') as [string, string]));
    const dashed = 'stroke-dasharray' in values;

    return (
        <svg aria-hidden="true" width="22" height="14" viewBox="0 0 22 14" className="shrink-0">
            <rect
                x="1"
                y="1"
                width="20"
                height="12"
                rx="3"
                fill={values.fill === 'none' || !values.fill ? 'transparent' : values.fill}
                stroke={values.stroke ?? values.fill ?? 'currentColor'}
                strokeWidth="1.5"
                strokeDasharray={dashed ? '3 2' : undefined}
            />
        </svg>
    );
}
