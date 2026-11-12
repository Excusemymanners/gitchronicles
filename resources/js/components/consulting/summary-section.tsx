import ReportSection from '@/components/consulting/report-section';
import { SEVERITIES, SEVERITY_LABELS, SeverityIcon } from '@/components/consulting/severity-badge';
import { countLabel, formatNumber } from '@/lib/format';
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
    const { counts, summary, code_statistics: statistics } = result;
    const codeFindings = counts.bugs + counts.code_vulnerabilities;

    return (
        <ReportSection id="rezumat" title="Rezumat">
            {summary && (
                <figure className="mb-6 border-l-2 border-accent pl-4">
                    <blockquote lang="en" className="leading-7 text-ink">
                        {summary}
                    </blockquote>
                    <figcaption className="mt-2 text-[0.8125rem] text-ink-subtle">Rezumat scris de AI, în engleză, doar din cifrele acestui raport.</figcaption>
                </figure>
            )}

            <h3 className="text-sm font-medium text-ink-muted">Probleme după gravitate</h3>
            <dl className="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5">
                {SEVERITIES.map((severity) => (
                    <div key={severity} className="rounded-lg border border-line bg-surface px-3 py-2.5 shadow-card">
                        <dt className="flex items-center gap-1.5 text-[0.8125rem] text-ink-muted">
                            <SeverityIcon severity={severity} className={`size-3 ${iconTone[severity]}`} />
                            {SEVERITY_LABELS[severity]}
                        </dt>
                        <dd className="mt-1 text-xl font-semibold tracking-tight text-ink tabular-nums">{formatNumber(counts[severity])}</dd>
                    </div>
                ))}
            </dl>

            <dl className="mt-4 grid gap-2 sm:grid-cols-3">
                <KeyNumber
                    label="Probleme în cod"
                    value={formatNumber(codeFindings)}
                    detail={`${countLabel(counts.bugs, 'bug', 'bug-uri')}, ${countLabel(counts.code_vulnerabilities, 'vulnerabilitate', 'vulnerabilități')}`}
                />
                <KeyNumber
                    label="Dependențe vulnerabile"
                    value={formatNumber(counts.vulnerable_packages)}
                    detail={countLabel(counts.dependency_advisories, 'avertisment de securitate', 'avertismente de securitate')}
                />
                {statistics && (
                    <KeyNumber
                        label="Linii de cod"
                        value={formatNumber(statistics.totals.code)}
                        detail={`${countLabel(statistics.languages.length, 'limbaj', 'limbaje')}, ${countLabel(statistics.totals.files, 'fișier', 'fișiere')}`}
                    />
                )}
            </dl>

            {result.omitted_count > 0 && (
                <p className="mt-4 text-sm text-ink-muted">
                    Limita pe unealtă a lăsat pe dinafară cele mai puțin grave probleme: {countLabel(result.omitted_count, 'problemă', 'probleme')}.
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
