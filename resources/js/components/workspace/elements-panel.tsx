import { useId } from 'react';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import SeverityBadge, { SEVERITIES, SEVERITY_LABELS } from '@/components/consulting/severity-badge';
import MediaStatusBadge from '@/components/media/media-status-badge';
import ElementCard from '@/components/workspace/element-card';
import { countLabel, formatNumber, formatTimecode } from '@/lib/format';
import { formatDay, formatMonth } from '@/lib/history-format';
import type { WorkspaceElements } from '@/types';
import type { Activity } from '@/types/history';

type Props = {
    elements: WorkspaceElements;
    /** „column”: coloana din dreapta, pe ecrane late; „below”: sub conținutul capitolului, pe ecrane mai înguste. */
    placement: 'column' | 'below';
};

/**
 * Panoul „Elemente”, ca în NotebookLM: ce a generat GitChronicle pentru repository (diagrama de arhitectură,
 * video-ul, slide-urile, activitatea, zonele fierbinți, consultanța), fiecare cu cardul lui. Un card cu date deschide
 * capitolul lui; unul fără date spune doar că nu a fost generat încă.
 *
 * Previzualizarea diagramei folosește MermaidDiagram din tab-ul „Diagrame”. Activitatea și zonele fierbinți vin de la același serviciu ca tab-ul „Istoric” (HistoryStatistics),
 * deci cifrele de aici sunt aceleași cu cele de acolo.
 */
export default function ElementsPanel({ elements, placement }: Props) {
    const headingId = useId();
    const { architecture, video, slides, activity, hotspots, consulting } = elements;

    return (
        <section
            aria-labelledby={headingId}
            className={placement === 'column' ? 'px-4 pt-5 pb-10' : 'mx-auto w-full max-w-190 px-4 pb-6 sm:px-8'}
        >
            <div className={`mb-3 flex items-baseline justify-between gap-3 px-1 ${placement === 'below' ? 'border-t border-line pt-8' : ''}`}>
                <h2 id={headingId} className="font-brand text-[0.9375rem] font-bold text-ink">
                    Elemente
                </h2>
                <p className="text-xs text-ink-subtle">generate din repository</p>
            </div>

            <ul className={placement === 'column' ? 'flex flex-col gap-3' : 'grid gap-3 sm:grid-cols-2'}>
                <ElementCard
                    title={architecture?.title ?? 'Arhitectura pe straturi'}
                    description="Cum se leagă părțile aplicației, desenat din importurile reale."
                    href={architecture?.href ?? null}
                    actionLabel="Deschide diagramele"
                    emptyText="Încă nu a fost generată."
                >
                    {architecture && (
                        // Diagrama micșorată ca să încapă toată; tot cardul duce la vederea completă.
                        <div className="h-36 overflow-hidden rounded-lg border border-line-subtle bg-canvas p-2">
                            <MermaidDiagram code={architecture.mermaid} title={architecture.title} compact />
                        </div>
                    )}
                </ElementCard>

                <ElementCard
                    title="Video de prezentare"
                    description="Un video scurt despre ce face proiectul, făcut din datele lui."
                    href={video?.href ?? null}
                    actionLabel="Vezi video-ul"
                    emptyText="Încă nu a fost generat."
                >
                    {video &&
                        (video.poster ? (
                            // Posterul e un cadru din video (vertical, 9:16); durata stă în colțul lui, ca pe un player.
                            <div className="relative w-24 overflow-hidden rounded-lg border border-line-subtle bg-canvas">
                                <img src={video.poster} alt="" loading="lazy" className="block aspect-9/16 w-full object-cover" />
                                {video.duration_ms !== null && (
                                    <span className="absolute right-1 bottom-1 rounded-sm bg-canvas/80 px-1 text-[0.6875rem] text-ink tabular-nums">
                                        {formatTimecode(video.duration_ms)}
                                    </span>
                                )}
                            </div>
                        ) : (
                            <MediaStatusBadge status={video.status} label={video.status_label} />
                        ))}
                </ElementCard>

                <ElementCard
                    title="Prezentare (slide-uri)"
                    description="Slide-uri despre repository, de descărcat ca PDF sau PPTX."
                    href={slides?.href ?? null}
                    actionLabel="Vezi slide-urile"
                    emptyText="Încă nu au fost generate."
                >
                    {slides &&
                        (slides.slide_count !== null ? (
                            <p className="text-[0.8125rem] text-ink">{countLabel(slides.slide_count, 'slide', 'slide-uri')}, în format PDF și PPTX.</p>
                        ) : (
                            <MediaStatusBadge status={slides.status} label={slides.status_label} />
                        ))}
                </ElementCard>

                <ElementCard
                    title="Activitate"
                    description="Commit-urile din istoricul git, în timp."
                    href={activity?.href ?? null}
                    actionLabel="Vezi istoricul"
                    emptyText="Istoricul nu a fost importat încă."
                >
                    {activity && (
                        <>
                            <p className="text-[0.8125rem] text-ink">
                                {countLabel(activity.commit_count, 'commit', 'commit-uri')} de la {formatDay(activity.first_commit_at)} până la{' '}
                                {formatDay(activity.last_commit_at)}, de la {countLabel(activity.contributor_count, 'contribuitor', 'contribuitori')}.
                            </p>
                            <MonthBars activity={activity} />
                        </>
                    )}
                </ElementCard>

                <ElementCard
                    title="Zone fierbinți"
                    description="Fișierele schimbate cel mai des."
                    href={hotspots?.href ?? null}
                    actionLabel="Vezi zonele fierbinți"
                    emptyText="Istoricul nu a fost importat încă."
                >
                    {hotspots && (
                        <ol className="divide-y divide-line text-[0.8125rem]">
                            {hotspots.files.map((file) => (
                                <li key={file.path} className="flex items-baseline gap-3 py-1.5">
                                    <code className="min-w-0 flex-1 truncate font-mono text-xs text-ink" title={file.path}>
                                        {file.path}
                                    </code>
                                    <span className="shrink-0 text-xs text-ink-muted tabular-nums">
                                        {formatNumber(file.commit_count)}
                                        <span className="sr-only"> {file.commit_count === 1 ? 'commit' : 'commit-uri'}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </ElementCard>

                <ElementCard
                    title="Consultanță"
                    description="Bug-uri, vulnerabilități și zonele de risc ale codului."
                    href={consulting?.href ?? null}
                    actionLabel="Vezi raportul"
                    emptyText="Încă nu a fost generat."
                >
                    {consulting && <ConsultingPreview consulting={consulting} />}
                </ElementCard>
            </ul>
        </section>
    );
}

/** Conținutul cardului „Consultanță”: problemele după gravitate când raportul e gata, altfel starea lui. */
function ConsultingPreview({ consulting }: { consulting: NonNullable<WorkspaceElements['consulting']> }) {
    if (consulting.counts !== null) {
        const { counts } = consulting;

        return (
            <ul className="flex flex-wrap gap-1.5">
                {SEVERITIES.filter((severity) => severity !== 'info').map((severity) => (
                    <li key={severity}>
                        <SeverityBadge severity={severity} label={`${SEVERITY_LABELS[severity]}: ${formatNumber(counts[severity])}`} />
                    </li>
                ))}
                <li className="basis-full text-[0.8125rem] text-ink">
                    {countLabel(counts.vulnerable_packages, 'pachet vulnerabil', 'pachete vulnerabile')} în dependențe.
                </li>
            </ul>
        );
    }

    if (consulting.status === null || consulting.status_label === null) {
        return <p className="text-[0.8125rem] text-ink-muted">Analiza nu a rulat încă.</p>;
    }

    return <MediaStatusBadge status={consulting.status} label={consulting.status_label} />;
}

/**
 * Graficul mic al cardului „Activitate”: o bară pe lună pentru ultimele 12 luni (luna curentă ultima).
 * O lună neimportată nu are bară. Fără niciun commit în aceste luni, graficul lipsește.
 * E doar decorativ (tot cardul e o legătură, deci barele nu au indicii la hover): cititoarele de ecran
 * au cifrele în textul de deasupra, iar tabelul lunilor e în „Istoric”.
 */
function MonthBars({ activity }: { activity: Activity }) {
    const counts = activity.months.map((month) => month.commit_count ?? 0);
    const maxCount = Math.max(...counts, 1);

    if (counts.every((count) => count === 0)) {
        return null;
    }

    const firstMonth = activity.months[0];
    const lastMonth = activity.months[activity.months.length - 1];

    return (
        <div aria-hidden="true" className="mt-3">
            <div className="flex h-10 items-end gap-0.5 border-b border-line">
                {activity.months.map((month) => (
                    <span
                        key={month.month}
                        className="block flex-1 rounded-t-[2px] bg-accent"
                        style={{ height: month.commit_count ? `${Math.max((month.commit_count / maxCount) * 100, 8)}%` : 0 }}
                    />
                ))}
            </div>
            <div className="mt-1 flex justify-between text-[0.6875rem] text-ink-muted">
                <span>{formatMonth(firstMonth.month, true)}</span>
                <span>{formatMonth(lastMonth.month, true)}</span>
            </div>
        </div>
    );
}
