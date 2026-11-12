import ReportSection from '@/components/consulting/report-section';
import SeverityBadge from '@/components/consulting/severity-badge';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';
import type { ConsultingResult } from '@/types/consulting';

/**
 * „Zone de risc”: fișierele schimbate des care au și probleme, cu scorul calculat de server (RiskRanking).
 * Formula e scrisă sub titlu, cu ponderile primite de la server, ca oricine să poată reface calculul.
 */
export default function RiskZonesSection({ risk }: { risk: ConsultingResult['risk'] }) {
    const { t, plural } = useI18n();
    const { weights, candidate_count: candidateCount, zones } = risk;
    const maxScore = Math.max(...zones.map((zone) => zone.score), 1);

    return (
        <ReportSection
            id="zone-de-risc"
            title={t('consulting.risk.title')}
            description={t('consulting.risk.description', {
                critical: weights.critical,
                high: weights.high,
                medium: weights.medium,
                low: weights.low,
                info: weights.info,
                count: candidateCount,
            })}
        >
            {zones.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    {t('consulting.risk.empty')}
                </p>
            ) : (
                <ol className="flex flex-col gap-4">
                    {zones.map((zone) => (
                        <li key={zone.path} className="text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                <a
                                    href={zone.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex min-w-0 items-center gap-1 rounded-sm font-mono text-xs break-all text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                >
                                    {zone.path}
                                    <ExternalLinkIcon className="size-3.5" />
                                    <span className="sr-only"> {t('consulting.onGitHub')}</span>
                                </a>
                                <span className="flex items-center gap-2">
                                    <SeverityBadge severity={zone.worst_severity} />
                                    <span className="font-semibold text-ink tabular-nums">{t('consulting.risk.score', { score: formatNumber(zone.score) })}</span>
                                </span>
                            </div>
                            <span aria-hidden="true" className="mt-1.5 block h-1.5 rounded-full bg-surface-2">
                                <span className="block h-1.5 rounded-full bg-warning" style={{ width: `${Math.max((zone.score / maxScore) * 100, 2)}%` }} />
                            </span>
                            <p className="mt-1 text-[0.8125rem] text-ink-muted">
                                {t('consulting.risk.formula', {
                                    commits: plural('commits', zone.commit_count),
                                    points: plural('severityPoints', zone.severity_points),
                                    problems: plural('problems', zone.finding_count),
                                })}
                            </p>
                        </li>
                    ))}
                </ol>
            )}
        </ReportSection>
    );
}
