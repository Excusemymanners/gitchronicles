import ReportSection from '@/components/consulting/report-section';
import { countLabel, formatNumber } from '@/lib/format';
import type { ConsultingTool, ToolError } from '@/types/consulting';

/** Ce face fiecare unealtă, într-o propoziție. */
const PURPOSE: Record<ConsultingTool['key'], string> = {
    scc: 'Numără limbajele, liniile de cod, comentariile și complexitatea.',
    'osv-scanner': 'Caută dependențele cu vulnerabilități cunoscute în baza de date OSV.',
    semgrep: 'Caută bug-uri și probleme de securitate în cod, cu regulile scrise pentru GitChronicle.',
};

/** De ce nu a mers o unealtă, după codul și numărul primite de la server. */
export function toolErrorText(error: ToolError, value: number | null): string {
    switch (error) {
        case 'timed_out':
            return `A durat mai mult de ${value} s, așa că am oprit-o.`;
        case 'output_too_large':
            return `A scris mai mult de ${value} MB, așa că am oprit-o.`;
        case 'unreadable_output':
            return `Nu a dat un răspuns pe care să-l putem citi (codul de ieșire ${value}).`;
        case 'exit_code':
            return `S-a oprit cu o eroare (codul de ieșire ${value}).`;
    }
}

/** Ce a citit unealta, din detaliile ei: fișierele lui Semgrep, pachetele lui OSV-Scanner. */
function scannedText(tool: ConsultingTool): string | null {
    if (tool.key === 'semgrep' && tool.details.files_scanned !== undefined) {
        return `${countLabel(tool.details.files_scanned, 'fișier citit', 'fișiere citite')}, ${countLabel(tool.finding_count, 'problemă', 'probleme')}.`;
    }

    if (tool.key === 'osv-scanner' && tool.details.packages_scanned !== undefined) {
        return `${countLabel(tool.details.packages_scanned, 'pachet verificat', 'pachete verificate')}, ${countLabel(tool.finding_count, 'avertisment', 'avertismente')}.`;
    }

    return null;
}

/**
 * „Unelte”: ce unelte au rulat (versiunea și durata), ce au citit și de ce n-a mers una care a eșuat sau
 * nu e instalată. Dedesubt, ce iese de pe server în timpul unei analize.
 */
export default function ToolsSection({ tools }: { tools: ConsultingTool[] }) {
    return (
        <ReportSection id="unelte" title="Unelte">
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
                                        ? `${tool.status_label} în ${formatNumber(Math.round(tool.duration_ms / 100) / 10)} s`
                                        : tool.status_label}
                                </p>
                            </div>
                            <p className="mt-0.5 text-ink-muted">{PURPOSE[tool.key]}</p>
                            {tool.status === 'ran' && scanned && <p className="mt-0.5 text-[0.8125rem] text-ink-subtle">{scanned}</p>}
                            {tool.status === 'not_installed' && (
                                <p className="mt-0.5 text-[0.8125rem] text-warning-ink">Nu e instalată pe server (composer consulting:install), așa că secțiunea ei e goală.</p>
                            )}
                            {tool.error && <p className="mt-0.5 text-[0.8125rem] text-danger-ink">{toolErrorText(tool.error, tool.error_value)}</p>}
                            {tool.omitted_count > 0 && (
                                <p className="mt-0.5 text-[0.8125rem] text-ink-subtle">
                                    Limita pe unealtă a lăsat pe dinafară cele mai puțin grave probleme: {countLabel(tool.omitted_count, 'problemă', 'probleme')}.
                                </p>
                            )}
                        </li>
                    );
                })}
            </ul>
            <p className="mt-3 text-[0.8125rem] text-ink-subtle">
                Semgrep și scc doar citesc clona, fără să trimită ceva. OSV-Scanner trimite la osv.dev numele, versiunea și ecosistemul pachetelor din
                fișierele lock (date publice, repository-ul fiind public). Niciuna nu rulează codul repository-ului.
            </p>
        </ReportSection>
    );
}
