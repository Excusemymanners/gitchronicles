import ReportSection from '@/components/consulting/report-section';
import { SEVERITIES, SeverityIcon } from '@/components/consulting/severity-badge';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';
import type { ConsultingResult, Severity } from '@/types/consulting';

/** Culoarea formei fiecărei trepte (forma și eticheta spun același lucru fără culoare). */
const iconTone: Record<Severity, string> = {
    critical: 'text-danger',
    high: 'text-danger',
    medium: 'text-warning',
    low: 'text-info',
    info: 'text-ink-subtle',
};

/**
 * „Rezumat”: rezumatul scris de AI (dacă există), problemele după gravitate și trei cifre — problemele din cod,
 * dependențele vulnerabile și mărimea codului. Toate vin din raport.
 */
export default function SummarySection({ result }: { result: ConsultingResult }) {
    const { t, plural } = useI18n();
    const { counts, summary, code_statistics: statistics } = result;
    const codeFindings = counts.bugs + counts.code_vulnerabilities;

    return (
        <ReportSection id="rezumat" title={t('consulting.summary.title')}>
            {summary && (
                <figure className="mb-6 border-l-2 border-accent pl-4">
                    <blockquote lang="en" className="leading-7 text-ink">
                        {summary}
                    </blockquote>
                    <figcaption className="mt-2 text-[0.8125rem] text-ink-subtle">{t('consulting.summary.aiNote')}</figcaption>
                </figure>
            )}

            <h3 className="text-sm font-medium text-ink-muted">{t('consulting.summary.bySeverity')}</h3>
            <dl className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                {SEVERITIES.map((severity) => (
                    <div key={severity} className="rounded-lg border border-line bg-surface px-3 py-2.5 shadow-card">
                        <dt className="flex items-center gap-1.5 text-[0.8125rem] text-ink-muted">
                            <SeverityIcon severity={severity} className={`size-3 ${iconTone[severity]}`} />
                            {t(`severities.${severity}`)}
                        </dt>
                        <dd className="mt-1 text-xl font-semibold tracking-tight text-ink tabular-nums">{formatNumber(counts[severity])}</dd>
                    </div>
                ))}
            </dl>

            <dl className="mt-4 grid gap-2 sm:grid-cols-3">
                <KeyNumber
                    label={t('consulting.summary.codeProblems')}
                    value={formatNumber(codeFindings)}
                    detail={t('consulting.summary.bugsAndVulnerabilities', {
                        bugs: plural('bugs', counts.bugs),
                        vulnerabilities: plural('codeVulnerabilities', counts.code_vulnerabilities),
                    })}
                />
                <KeyNumber
                    label={t('consulting.summary.vulnerableDependencies')}
                    value={formatNumber(counts.vulnerable_packages)}
                    detail={plural('advisories', counts.dependency_advisories)}
                />
                {statistics && (
                    <KeyNumber
                        label={t('consulting.summary.linesOfCode')}
                        value={formatNumber(statistics.totals.code)}
                        detail={t('consulting.summary.languagesAndFiles', {
                            languages: plural('languages', statistics.languages.length),
                            files: plural('files', statistics.totals.files),
                        })}
                    />
                )}
            </dl>

            {result.omitted_count > 0 && (
                <p className="mt-4 text-sm text-ink-muted">
                    {t('consulting.omitted', { problems: plural('problems', result.omitted_count) })}
                </p>
            )}
        </ReportSection>
    );
}

function KeyNumber({ label, value, detail }: { label: string; value: string; detail: string }) {
    return (
        <div className="rounded-lg border border-line bg-surface px-4 py-3 shadow-card">
            <dt className="text-sm text-ink-subtle">{label}</dt>
            <dd className="mt-1">
                <span className="block text-xl font-semibold tracking-tight text-ink tabular-nums">{value}</span>
                <span className="block text-[0.8125rem] text-ink-muted">{detail}</span>
            </dd>
        </div>
    );
}
