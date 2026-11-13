import ReportSection from '@/components/consulting/report-section';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber, formatPercent } from '@/lib/format';
import type { ConsultingResult } from '@/types/consulting';

/**
 * „Statistici despre cod”: ce a numărat scc pentru fiecare limbaj — fișiere, linii de cod, comentarii și
 * complexitate (ramificațiile și buclele numărate de scc) — cu partea fiecărui limbaj din liniile de cod.
 */
export default function CodeStatisticsSection({ statistics }: { statistics: ConsultingResult['code_statistics'] }) {
    const { t } = useI18n();

    return (
        <ReportSection
            id="statistici-cod"
            title={t('consulting.statistics.title')}
            description={t('consulting.statistics.description')}
        >
            {statistics === null || statistics.languages.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    {t('consulting.statistics.empty')}
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-line text-ink-subtle">
                            <tr>
                                <th scope="col" className="px-3 py-2 font-medium">
                                    {t('consulting.statistics.language')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('consulting.statistics.files')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('consulting.statistics.linesOfCode')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('consulting.statistics.comments')}
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    {t('consulting.statistics.complexity')}
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-line-subtle">
                            {statistics.languages.map((language) => {
                                const share = statistics.totals.code === 0 ? 0 : language.code / statistics.totals.code;

                                return (
                                    <tr key={language.name}>
                                        <th scope="row" className="px-3 py-2 font-normal text-ink">
                                            {language.name}
                                            <span aria-hidden="true" className="mt-1 block h-1 w-full max-w-40 rounded-full bg-surface-2">
                                                <span className="block h-1 rounded-full bg-ink/60" style={{ width: `${Math.max(share * 100, 1)}%` }} />
                                            </span>
                                        </th>
                                        <td className="px-3 py-2 text-right text-ink-muted tabular-nums">{formatNumber(language.files)}</td>
                                        <td className="px-3 py-2 text-right text-ink-muted tabular-nums">
                                            {formatNumber(language.code)} <span className="text-xs text-ink-subtle">({formatPercent(share)})</span>
                                        </td>
                                        <td className="px-3 py-2 text-right text-ink-muted tabular-nums">{formatNumber(language.comments)}</td>
                                        <td className="px-3 py-2 text-right text-ink-muted tabular-nums">{formatNumber(language.complexity)}</td>
                                    </tr>
                                );
                            })}
                        </tbody>
                        <tfoot className="border-t border-line font-medium text-ink">
                            <tr>
                                <th scope="row" className="px-3 py-2 text-left font-medium">
                                    {t('consulting.statistics.total')}
                                </th>
                                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(statistics.totals.files)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(statistics.totals.code)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(statistics.totals.comments)}</td>
                                <td className="px-3 py-2 text-right tabular-nums">{formatNumber(statistics.totals.complexity)}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            )}
        </ReportSection>
    );
}
