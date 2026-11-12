import ReportSection from '@/components/consulting/report-section';
import SeverityBadge, { SEVERITIES, SeverityIcon } from '@/components/consulting/severity-badge';
import { ExternalLinkIcon } from '@/components/icons';
import { ChevronIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { translate } from '@/lib/i18n';
import type { CodeFile, CodeFinding } from '@/types/consulting';

/** Câte fișiere sunt deschise de la început (cele mai grave); celelalte se deschid la clic. */
const OPEN_FILES = 3;

/**
 * „Probleme în cod”: ce a găsit Semgrep, grupat pe fișiere (cel mai grav fișier primul). Fiecare fișier e un
 * <details> care se deschide cu tastatura; problemele aceleiași reguli stau împreună, iar fiecare duce la rândurile
 * ei pe GitHub, la commitul analizat.
 * Titlurile și mesajele regulilor sunt în engleză (lang="en"), ca tot ce generează aplicația.
 */
export default function CodeFindingsSection({ files }: { files: CodeFile[] }) {
    const { t, plural } = useI18n();
    const findingCount = files.reduce((total, file) => total + file.findings.length, 0);

    return (
        <ReportSection
            id="probleme-in-cod"
            title={t('consulting.findings.title')}
            description={
                files.length === 0
                    ? undefined
                    : t('consulting.findings.description', { problems: plural('problems', findingCount), files: plural('files', files.length) })
            }
        >
            {files.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    {t('consulting.findings.empty')}
                </p>
            ) : (
                <ul className="divide-y divide-line rounded-lg border border-line bg-surface">
                    {files.map((file, index) => (
                        <li key={file.path}>
                            <details open={index < OPEN_FILES} className="group">
                                <summary className="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm hover:bg-surface-2 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-accent [&::-webkit-details-marker]:hidden">
                                    <ChevronIcon className="size-3.5 shrink-0 text-ink-subtle group-open:rotate-90 motion-safe:transition-transform" />
                                    <SeverityIcon severity={file.worst_severity} className="size-3 text-ink-muted" />
                                    <span className="sr-only">{t(`severities.${file.worst_severity}`)}:</span>
                                    <code className="min-w-0 flex-1 font-mono text-xs break-all text-ink">{file.path}</code>
                                    <span className="shrink-0 text-xs text-ink-subtle tabular-nums">{plural('problems', file.findings.length)}</span>
                                </summary>
                                <ul className="flex flex-col gap-4 border-t border-line-subtle px-3 py-3 sm:pl-9">
                                    {groupByRule(file.findings).map((group) => (
                                        <RuleItem key={group[0].id} findings={group} />
                                    ))}
                                </ul>
                            </details>
                        </li>
                    ))}
                </ul>
            )}
        </ReportSection>
    );
}

/**
 * Problemele aceleiași reguli dintr-un fișier, împreună: o regulă găsită de 29 de ori într-un fișier are un singur
 * titlu și un singur mesaj, urmate de legăturile spre fiecare loc. Ordinea e cea primită (după rând).
 */
function groupByRule(findings: CodeFinding[]): CodeFinding[][] {
    const groups = new Map<string, CodeFinding[]>();

    for (const finding of findings) {
        groups.set(finding.rule_id, [...(groups.get(finding.rule_id) ?? []), finding]);
    }

    return [...groups.values()].sort((a, b) => SEVERITIES.indexOf(a[0].severity) - SEVERITIES.indexOf(b[0].severity));
}

/** Rândurile unei probleme, de exemplu „rândul 12” sau „rândurile 12–15”. */
function linesLabel(finding: CodeFinding): string {
    if (finding.start_line === null) {
        return translate('consulting.findings.wholeFile');
    }

    return finding.end_line === null || finding.end_line === finding.start_line
        ? translate('consulting.findings.line', { line: finding.start_line })
        : translate('consulting.findings.lines', { start: finding.start_line, end: finding.end_line });
}

/** O regulă găsită într-un fișier: gravitatea, titlul, felul, mesajul și legăturile spre fiecare loc pe GitHub. */
function RuleItem({ findings }: { findings: CodeFinding[] }) {
    const { t } = useI18n();
    const first = findings[0];

    return (
        <li className="text-sm">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <SeverityBadge severity={first.severity} />
                <span lang="en" className="font-medium text-ink">
                    {first.title}
                </span>
                <span className="text-xs text-ink-subtle">{t(`consulting.findings.kind.${first.kind}`)}</span>
                <code className="font-mono text-xs text-ink-subtle">{first.rule_id}</code>
            </div>
            <p lang="en" className="mt-1 text-ink-muted">
                {first.message}
            </p>
            <ul aria-label={t('consulting.findings.places')} className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
                {findings.map((finding) => (
                    <li key={finding.id}>
                        <a
                            href={finding.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="inline-flex items-center gap-1 rounded-sm font-medium text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                        >
                            {linesLabel(finding)}
                            <ExternalLinkIcon className="size-3.5" />
                            <span className="sr-only"> {t('consulting.onGitHub')}</span>
                        </a>
                    </li>
                ))}
            </ul>
        </li>
    );
}
