import ReportSection from '@/components/consulting/report-section';
import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { formatNumber } from '@/lib/format';
import { pluralize, translate } from '@/lib/i18n';
import type { ConsultingTool, ToolError } from '@/types/consulting';

/** Ce face fiecare unealtă, într-o propoziție. */
const PURPOSE: Record<ConsultingTool['key'], TranslationKey> = {
    scc: 'consulting.tools.purpose.scc',
    'osv-scanner': 'consulting.tools.purpose.osv-scanner',
    semgrep: 'consulting.tools.purpose.semgrep',
};

/** De ce nu a mers o unealtă, după codul și numărul primite de la server. */
export function toolErrorText(error: ToolError, value: number | null): string {
    return translate(`consulting.tools.error.${error}`, { value: value ?? '' });
}

/** Ce a citit unealta, din detaliile ei: fișierele lui Semgrep, pachetele lui OSV-Scanner. */
function scannedText(tool: ConsultingTool): string | null {
    if (tool.key === 'semgrep' && tool.details.files_scanned !== undefined) {
        return translate('consulting.tools.semgrepScanned', { files: pluralize('filesRead', tool.details.files_scanned), problems: pluralize('problems', tool.finding_count) });
    }

    if (tool.key === 'osv-scanner' && tool.details.packages_scanned !== undefined) {
        return translate('consulting.tools.osvScanned', {
            packages: pluralize('packagesChecked', tool.details.packages_scanned),
            advisories: pluralize('advisories', tool.finding_count),
        });
    }

    return null;
}

/**
 * „Unelte”: ce unelte au rulat (versiunea și durata), ce au citit și de ce n-a mers una care a eșuat sau
 * nu e instalată. Dedesubt, ce iese de pe server în timpul unei analize.
 */
export default function ToolsSection({ tools }: { tools: ConsultingTool[] }) {
    const { t, plural } = useI18n();

    return (
        <ReportSection id="unelte" title={t('consulting.tools.title')}>
            <ul className="divide-y divide-line rounded-lg border border-line bg-surface">
                {tools.map((tool) => {
                    const scanned = scannedText(tool);

                    return (
                        <li key={tool.key} className="px-3 py-3 text-sm">
                            <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                                <p className="font-medium text-ink">
                                    {tool.name}
                                    {tool.version && <span className="font-normal text-ink-subtle"> {tool.version}</span>}
                                </p>
                                <p className="text-xs text-ink-muted">
                                    {tool.status === 'ran' && tool.duration_ms !== null
                                        ? t('consulting.tools.ranIn', {
                                              status: t(`consulting.tools.status.${tool.status}`),
                                              seconds: formatNumber(Math.round(tool.duration_ms / 100) / 10),
                                          })
                                        : t(`consulting.tools.status.${tool.status}`)}
                                </p>
                            </div>
                            <p className="mt-0.5 text-ink-muted">{t(PURPOSE[tool.key])}</p>
                            {tool.status === 'ran' && scanned && <p className="mt-0.5 text-[0.8125rem] text-ink-subtle">{scanned}</p>}
                            {tool.status === 'not_installed' && (
                                <p className="mt-0.5 text-[0.8125rem] text-warning-ink">{t('consulting.tools.notInstalled')}</p>
                            )}
                            {tool.error && <p className="mt-0.5 text-[0.8125rem] text-danger-ink">{toolErrorText(tool.error, tool.error_value)}</p>}
                            {tool.omitted_count > 0 && (
                                <p className="mt-0.5 text-[0.8125rem] text-ink-subtle">
                                    {t('consulting.omitted', { problems: plural('problems', tool.omitted_count) })}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
            <p className="mt-3 text-[0.8125rem] text-ink-subtle">
                {t('consulting.tools.privacy')}
            </p>
        </ReportSection>
    );
}
