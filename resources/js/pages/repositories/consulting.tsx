import { Head, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import CodeFindingsSection from '@/components/consulting/code-findings-section';
import CodeStatisticsSection from '@/components/consulting/code-statistics-section';
import DependenciesSection from '@/components/consulting/dependencies-section';
import RiskZonesSection from '@/components/consulting/risk-zones-section';
import RunAnalysisButton from '@/components/consulting/run-analysis-button';
import SummarySection from '@/components/consulting/summary-section';
import ToolsSection from '@/components/consulting/tools-section';
import DownloadLink from '@/components/media/download-link';
import RepositoryLayout from '@/layouts/repository-layout';
import { formatDate, shortSha } from '@/lib/format';
import type { RepositoryPageProps } from '@/types';
import type { ConsultingError, ConsultingReport } from '@/types/consulting';

type Props = RepositoryPageProps & {
    /** Cel mai nou raport (ConsultingReportResource în Laravel); `null` cât timp analiza nu a rulat niciodată. */
    consulting: ConsultingReport | null;
    /** Adevărat pentru un utilizator autentificat, pe un repository gata, cât timp nicio analiză nu așteaptă sau rulează. */
    can_run_consulting: boolean;
};

/** De ce a eșuat un raport, după codul primit de la server. */
const ERROR_TEXT: Record<ConsultingError, string> = {
    clone_missing: 'Clona repository-ului nu mai există pe server. Procesează din nou repository-ul, apoi rulează analiza.',
    not_installed: 'Niciuna dintre unelte nu e instalată pe server (composer consulting:install).',
    no_tool_ran: 'Nicio unealtă nu a putut rula; motivul fiecăreia e mai jos.',
    unexpected: 'Analiza s-a oprit din cauza unei erori neașteptate. Încearcă din nou mai târziu.',
};

/**
 * Pagina „Consultanță”: raportul cu bug-urile, vulnerabilitățile și zonele de risc ale codului, făcut de Semgrep,
 * OSV-Scanner și scc pe clona repository-ului, după ce acesta e gata (pe coada „media”).
 * Stări: nefăcut încă, în coadă sau în lucru (pagina se actualizează singură), eșuat cu motivul real, gata.
 */
export default function RepositoriesConsulting({ repository, consulting, can_run_consulting: canRun }: Props) {
    const isWorking = consulting?.is_working ?? false;

    // Cât timp analiza e în coadă sau rulează, cerem serverului la fiecare 3 secunde doar raportul și dreptul de a rula.
    const { start, stop } = usePoll(3000, { only: ['consulting', 'can_run_consulting'] }, { autoStart: false });

    useEffect(() => {
        if (isWorking) {
            start();
        } else {
            stop();
        }
    }, [isWorking, start, stop]);

    // Când analiza s-a terminat, cerem și spațiul de lucru: cardul din „Elemente” și capitolele se schimbă.
    const wasWorking = useRef(isWorking);

    useEffect(() => {
        if (wasWorking.current && !isWorking) {
            router.reload({ only: ['workspace'] });
        }

        wasWorking.current = isWorking;
    }, [isWorking]);

    const result = consulting?.result ?? null;

    return (
        <RepositoryLayout>
            <Head title={`Consultanță · ${repository.full_name}`} />

            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                    <h1 className="font-brand text-2xl font-bold tracking-tight text-ink">Consultanță</h1>
                    <p className="mt-2 max-w-[60ch] text-sm text-ink-muted">
                        Bug-urile, vulnerabilitățile și zonele de risc ale codului, găsite de Semgrep, OSV-Scanner și scc în clona repository-ului.
                        Toate cifrele vin din ce au găsit uneltele; problemele sunt indicii de verificat, nu verdicte.
                    </p>
                    {consulting?.sha && consulting.finished_at && consulting.status === 'ready' && (
                        <p className="mt-2 text-[0.8125rem] text-ink-subtle">
                            Commitul <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-ink">{shortSha(consulting.sha)}</code>, analizat pe{' '}
                            {formatDate(consulting.finished_at)}.
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {result && <DownloadLink href={result.download_url} label="Descarcă raportul (Markdown)" />}
                    {canRun && <RunAnalysisButton repositoryId={repository.id} hasReport={consulting !== null} />}
                </div>
            </div>

            {consulting === null && (
                <div className="mt-6 rounded-lg border border-dashed border-line-strong px-6 py-10 text-center text-sm text-ink-muted">
                    <p className="font-medium text-ink">Analiza nu a rulat încă pentru acest repository.</p>
                    <p className="mt-1">
                        {repository.status === 'ready'
                            ? 'Se face automat după fiecare procesare; un utilizator autentificat o poate porni și de aici.'
                            : 'Se face automat după ce repository-ul termină de procesat.'}
                    </p>
                </div>
            )}

            {isWorking && (
                <p role="status" className="mt-6 flex items-center gap-2 rounded-lg border border-info-line bg-info-soft px-4 py-3 text-sm text-info-ink">
                    <span aria-hidden="true" className="size-2 shrink-0 rounded-full bg-info motion-safe:animate-pulse" />
                    {consulting?.status === 'running'
                        ? 'Uneltele citesc codul… Pagina se actualizează singură când raportul e gata.'
                        : 'Analiza așteaptă în coadă… Pagina se actualizează singură când raportul e gata.'}
                </p>
            )}

            {consulting?.status === 'failed' && (
                <div role="alert" className="mt-6 rounded-lg border border-danger-line bg-danger-soft px-4 py-3 text-sm text-danger-ink">
                    <p className="font-medium">Raportul nu a putut fi făcut.</p>
                    <p className="mt-1">{ERROR_TEXT[consulting.error ?? 'unexpected']}</p>
                </div>
            )}

            {consulting?.is_stale && consulting.status === 'ready' && (
                <p className="mt-6 rounded-lg border border-warning-line bg-warning-soft px-4 py-3 text-sm text-warning-ink">
                    Raportul e făcut pentru un commit mai vechi; repository-ul a primit între timp commit-uri noi.
                </p>
            )}

            {result && consulting && (
                <div className="mt-8 flex flex-col gap-12">
                    <SummarySection result={result} />
                    <RiskZonesSection risk={result.risk} />
                    <CodeFindingsSection files={result.files} />
                    <DependenciesSection dependencies={result.dependencies} osvScanner={consulting.tools.find((tool) => tool.key === 'osv-scanner')} />
                    <CodeStatisticsSection statistics={result.code_statistics} />
                    <ToolsSection tools={consulting.tools} />
                </div>
            )}

            {consulting?.status === 'failed' && consulting.tools.length > 0 && (
                <div className="mt-8">
                    <ToolsSection tools={consulting.tools} />
                </div>
            )}
        </RepositoryLayout>
    );
}
