import ReportSection from '@/components/consulting/report-section';
import { ExternalLinkIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { formatDay } from '@/lib/format';
import type { ConsultingResult, RuntimeRow, RuntimeStatus } from '@/types/consulting';

/** Culorile stărilor (tonurile de stare ale temei); cuvântul spune același lucru fără culoare. */
const statusClasses: Record<RuntimeStatus, string> = {
    end_of_life: 'bg-danger-soft text-danger-ink ring-danger-line',
    behind: 'bg-warning-soft text-warning-ink ring-warning-line',
    security_only: 'bg-info-soft text-info-ink ring-info-line',
    current: 'bg-success-soft text-success-ink ring-success-line',
    unknown: 'bg-surface-2 text-ink-muted ring-line',
    unchecked: 'bg-surface-2 text-ink-muted ring-line',
};

/** Ce e de făcut, ca text: trecerea la o linie nouă, actualizarea sau planificarea trecerii. */
function actionOf(row: RuntimeRow): { key: TranslationKey; values: Record<string, string> } | null {
    if (row.status === 'end_of_life' && row.target !== null) {
        return { key: 'consulting.runtime.do.upgrade', values: { target: row.target } };
    }

    if (row.status === 'behind' && row.newest !== null) {
        return { key: 'consulting.runtime.do.patch', values: { version: row.newest } };
    }

    if (row.status === 'security_only' && row.target !== null) {
        return { key: 'consulting.runtime.do.plan', values: { target: row.target } };
    }

    return null;
}

/**
 * „Versiuni de runtime și framework”: ce versiuni declară repository-ul (PHP, Node.js, Python, Go, Ruby, Java, .NET,
 * frameworkurile mari), unde, ce stare au față de datele publice de suport, cea mai nouă versiune din linie și ce e
 * de făcut. Fără rețea, rândurile rămân și pagina spune că nu s-au putut verifica.
 */
export default function RuntimeVersionsSection({ runtime }: { runtime: NonNullable<ConsultingResult['runtime']> }) {
    const { t, plural } = useI18n();

    return (
        <ReportSection id="versiuni-runtime" title={t('consulting.runtime.title')} description={t('consulting.runtime.description')}>
            {runtime.error !== null && (
                <p role="status" className="mb-3 rounded-lg border border-warning-line bg-warning-soft px-4 py-3 text-sm text-warning-ink">
                    {t('consulting.runtime.couldNotCheck')}
                </p>
            )}

            {runtime.rows.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    {t('consulting.runtime.empty')}
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-line text-ink-subtle">
                            <tr>
                                <th scope="col" className="px-3 py-2 font-medium">{t('consulting.runtime.technology')}</th>
                                <th scope="col" className="px-3 py-2 font-medium">{t('consulting.runtime.declaredIn')}</th>
                                <th scope="col" className="px-3 py-2 font-medium">{t('consulting.runtime.status')}</th>
                                <th scope="col" className="px-3 py-2 font-medium">{t('consulting.runtime.newest')}</th>
                                <th scope="col" className="px-3 py-2 font-medium">{t('consulting.runtime.action')}</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-subtle">
                            {runtime.rows.map((row) => {
                                const action = actionOf(row);

                                return (
                                    <tr key={`${row.product}|${row.path}|${row.line}|${row.version}`} className="align-top">
                                        <th scope="row" className="px-3 py-2 font-normal text-ink">
                                            <span className="font-medium">{row.label}</span> <span className="tabular-nums">{row.version}</span>
                                            {row.is_range && (
                                                <span className="block text-xs text-ink-subtle">
                                                    {t('consulting.runtime.lowestAllowed', { declared: row.declared })}
                                                </span>
                                            )}
                                        </th>
                                        <td className="px-3 py-2 text-ink-muted">
                                            <code className="font-mono text-xs break-all text-ink">{row.path}</code>
                                            {row.line !== null && <span className="block text-xs text-ink-subtle">{t('consulting.runtime.line', { line: row.line })}</span>}
                                        </td>
                                        <td className="px-3 py-2">
                                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset ${statusClasses[row.status]}`}>
                                                {t(`consulting.runtime.statusLabel.${row.status}`)}
                                            </span>
                                            {row.status === 'unchecked' && row.reason !== null && (
                                                <span className="block text-xs text-ink-subtle">{t(`consulting.runtime.reason.${row.reason}`)}</span>
                                            )}
                                            {row.supported_until !== null && row.status !== 'unknown' && row.status !== 'unchecked' && (
                                                <span className="mt-1 block text-xs text-ink-subtle">
                                                    {row.status === 'end_of_life'
                                                        ? t('consulting.runtime.endedOn', { date: formatDay(row.supported_until) })
                                                        : t('consulting.runtime.supportedUntil', { date: formatDay(row.supported_until) })}
                                                </span>
                                            )}
                                            {(row.missing_security_releases ?? 0) > 0 && (
                                                <span className="block text-xs text-ink-subtle">
                                                    {t('consulting.runtime.missedSecurity', { releases: plural('securityReleases', row.missing_security_releases ?? 0) })}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-ink-muted tabular-nums">
                                            {row.newest !== null && row.url !== null ? (
                                                <a
                                                    href={row.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded-sm text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                                >
                                                    {row.newest}
                                                    <ExternalLinkIcon className="size-3.5" />
                                                    <span className="sr-only"> {t('consulting.runtime.sourceLink')}</span>
                                                </a>
                                            ) : (
                                                (row.newest ?? '—')
                                            )}
                                        </td>
                                        <td className="px-3 py-2 text-ink">{action === null ? <span className="text-ink-subtle">—</span> : t(action.key, action.values)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </ReportSection>
    );
}
