import { useId } from 'react';
import MermaidDiagram from '@/components/diagrams/mermaid-diagram';
import SeverityBadge, { SEVERITIES } from '@/components/consulting/severity-badge';
import MediaStatusBadge from '@/components/media/media-status-badge';
import ElementCard from '@/components/workspace/element-card';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber, formatTimecode } from '@/lib/format';
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
    const { t, plural } = useI18n();
    const headingId = useId();
    const { architecture, video, slides, activity, hotspots, consulting } = elements;

    return (
        <section
            aria-labelledby={headingId}
            className={placement === 'column' ? 'px-4 pt-5 pb-10' : 'mx-auto w-full max-w-190 px-4 pb-6 sm:px-8'}
        >
            <div className={`mb-3 flex items-baseline justify-between gap-3 px-1 ${placement === 'below' ? 'border-t border-line pt-8' : ''}`}>
                <h2 id={headingId} className="font-brand text-[0.9375rem] font-bold text-ink">
                    {t('elements.heading')}
                </h2>
                <p className="text-xs text-ink-subtle">{t('elements.subheading')}</p>
            </div>

            <ul className={placement === 'column' ? 'flex flex-col gap-3' : 'grid gap-3 sm:grid-cols-2'}>
                <ElementCard
                    title={architecture?.title ?? t('elements.architecture.fallbackTitle')}
                    description={t('elements.architecture.description')}
                    href={architecture?.href ?? null}
                    actionLabel={t('elements.architecture.action')}
                    emptyText={t('elements.architecture.empty')}
                >
                    {architecture && (
                        // Diagrama micșorată ca să încapă toată; tot cardul duce la vederea completă.
                        <div className="h-36 overflow-hidden rounded-lg border border-line-subtle bg-canvas p-2">
                            <MermaidDiagram code={architecture.mermaid} title={architecture.title} compact />
                        </div>
                    )}
                </ElementCard>

                <ElementCard
                    title={t('elements.video.title')}
                    description={t('elements.video.description')}
                    href={video?.href ?? null}
                    actionLabel={t('elements.video.action')}
                    emptyText={t('elements.video.empty')}
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
                    title={t('elements.slides.title')}
                    description={t('elements.slides.description')}
                    href={slides?.href ?? null}
                    actionLabel={t('elements.slides.action')}
                    emptyText={t('elements.slides.empty')}
                >
                    {slides &&
                        (slides.slide_count !== null ? (
                            <p className="text-[0.8125rem] text-ink">{t('elements.slides.summary', { slides: plural('slides', slides.slide_count) })}</p>
                        ) : (
                            <MediaStatusBadge status={slides.status} label={slides.status_label} />
                        ))}
                </ElementCard>

                <ElementCard
                    title={t('elements.activity.title')}
                    description={t('elements.activity.description')}
                    href={activity?.href ?? null}
                    actionLabel={t('elements.activity.action')}
                    emptyText={t('elements.activity.empty')}
                >
                    {activity && (
                        <>
                            <p className="text-[0.8125rem] text-ink">
                                {t('elements.activity.summary', {
                                    commits: plural('commits', activity.commit_count),
                                    first: formatDay(activity.first_commit_at),
                                    last: formatDay(activity.last_commit_at),
                                    contributors: plural('contributors', activity.contributor_count),
                                })}
                            </p>
                            <MonthBars activity={activity} />
                        </>
                    )}
                </ElementCard>

                <ElementCard
                    title={t('elements.hotspots.title')}
                    description={t('elements.hotspots.description')}
                    href={hotspots?.href ?? null}
                    actionLabel={t('elements.hotspots.action')}
                    emptyText={t('elements.hotspots.empty')}
                >
                    {hotspots && (
                        <ol className="divide-y divide-line text-[0.8125rem]">
                            {hotspots.files.map((file) => (
                                <li key={file.path} className="flex items-baseline gap-3 py-1.5">
                                    <code className="min-w-0 flex-1 truncate font-mono text-xs text-ink" title={file.path}>
                                        {file.path}
                                    </code>
                                    <span className="shrink-0 text-xs text-ink-muted tabular-nums">
                                        <span aria-hidden="true">{formatNumber(file.commit_count)}</span>
                                        <span className="sr-only">{plural('commits', file.commit_count)}</span>
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </ElementCard>

                <ElementCard
                    title={t('elements.consulting.title')}
                    description={t('elements.consulting.description')}
                    href={consulting?.href ?? null}
                    actionLabel={t('elements.consulting.action')}
                    emptyText={t('elements.consulting.empty')}
                >
                    {consulting && <ConsultingPreview consulting={consulting} />}
                </ElementCard>
            </ul>
        </section>
    );
}

/** Conținutul cardului „Consultanță”: problemele după gravitate când raportul e gata, altfel starea lui. */
function ConsultingPreview({ consulting }: { consulting: NonNullable<WorkspaceElements['consulting']> }) {
    const { t, plural } = useI18n();

    if (consulting.counts !== null) {
        const { counts } = consulting;

        return (
            <ul className="flex flex-wrap gap-1.5">
                {SEVERITIES.filter((severity) => severity !== 'info').map((severity) => (
                    <li key={severity}>
                        <SeverityBadge severity={severity} label={t('elements.consulting.severityCount', { severity: t(`severities.${severity}`), count: formatNumber(counts[severity]) })} />
                    </li>
                ))}
                <li className="basis-full text-[0.8125rem] text-ink">
                    {t('elements.consulting.vulnerablePackages', { packages: plural('vulnerablePackages', counts.vulnerable_packages) })}
                </li>
            </ul>
        );
    }

    if (consulting.status === null || consulting.status_label === null) {
        return <p className="text-[0.8125rem] text-ink-muted">{t('elements.consulting.notRun')}</p>;
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
