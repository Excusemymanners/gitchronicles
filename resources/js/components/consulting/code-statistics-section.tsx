import ReportSection from '@/components/consulting/report-section';
import { formatNumber, formatPercent } from '@/lib/format';
import type { ConsultingResult } from '@/types/consulting';

/**
 * „Statistici despre cod”: ce a numărat scc pentru fiecare limbaj — fișiere, linii de cod, comentarii și
 * complexitate (ramificațiile și buclele numărate de scc) — cu partea fiecărui limbaj din liniile de cod.
 */
export default function CodeStatisticsSection({ statistics }: { statistics: ConsultingResult['code_statistics'] }) {
    return (
        <ReportSection
            id="statistici-cod"
            title="Statistici despre cod"
            description="Numărate de scc, fără bibliotecile copiate în proiect, fișierele minificate sau generate și fișierele uriașe."
        >
            {statistics === null || statistics.languages.length === 0 ? (
                <p className="rounded-lg border border-dashed border-line-strong px-4 py-6 text-center text-sm text-ink-muted">
                    scc nu a rulat sau nu a găsit cod (vezi „Unelte” mai jos).
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-line bg-surface">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-line text-ink-subtle">
                            <tr>
                                <th scope="col" className="px-3 py-2 font-medium">
                                    Limbaj
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    Fișiere
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    Linii de cod
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    Comentarii
                                </th>
                                <th scope="col" className="px-3 py-2 text-right font-medium">
                                    Complexitate
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
                                                <span className="block h-1 rounded-full bg-accent" style={{ width: `${Math.max(share * 100, 1)}%` }} />
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
                                    Total
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
