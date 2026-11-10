import { countLabel, formatDate, formatNumber } from '@/lib/format';
import { formatDuration, formatUsd } from '@/lib/history-format';
import type { ProcessingMode, ProcessingRunItem, ProcessingRunStatus } from '@/types/history';

type Props = {
    /** Rulările care au procesat commit-ul, cea mai nouă prima. */
    runs: ProcessingRunItem[];
};

const modeLabels: Record<ProcessingMode, string> = {
    initial: 'prima procesare',
    incremental: 'actualizare incrementală',
    full: 'reprocesare completă',
};

const statusLabels: Record<ProcessingRunStatus, string> = {
    running: 'în curs',
    succeeded: 'reușită',
    failed: 'eșuată',
    skipped: 'nimic nou',
};

const statusDotClasses: Record<ProcessingRunStatus, string> = {
    running: 'bg-info motion-safe:animate-pulse',
    succeeded: 'bg-success',
    failed: 'bg-danger',
    skipped: 'bg-ink-subtle',
};

/**
 * Marcajul rulărilor GitChronicle pe commit-ul procesat: cea mai nouă rulare cu toate cifrele ei,
 * iar celelalte (dacă repository-ul a fost procesat de mai multe ori la același commit) într-o listă
 * care se deschide la cerere.
 */
export default function CommitRuns({ runs }: Props) {
    const [newest, ...older] = runs;

    return (
        <div className="mt-2 rounded-md border border-info-line bg-info-soft px-3 py-2 text-xs text-info-ink">
            <RunLine run={newest} />

            {older.length > 0 && (
                <details className="mt-1.5 group">
                    <summary className="cursor-pointer rounded-sm font-medium text-info-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent">
                        {older.length === 1 ? 'Încă o rulare' : `Încă ${countLabel(older.length, 'rulare', 'rulări')}`} pe acest commit
                    </summary>
                    <ul className="mt-1.5 flex flex-col gap-1.5 border-t border-info-line pt-1.5">
                        {older.map((run) => (
                            <li key={run.id}>
                                <RunLine run={run} />
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

/** O rulare pe un rând: cum a lucrat, cum s-a terminat și cifrele ei; o cifră necunoscută nu apare. */
function RunLine({ run }: { run: ProcessingRunItem }) {
    const details = [
        run.duration_ms !== null && `durată ${formatDuration(run.duration_ms)}`,
        run.documents_regenerated > 0 && countLabel(run.documents_regenerated, 'document regenerat', 'documente regenerate'),
        (run.input_tokens > 0 || run.output_tokens > 0) &&
            `${formatNumber(run.input_tokens)} tokeni intrare, ${formatNumber(run.output_tokens)} ieșire`,
        run.cost_usd_equivalent !== null && `≈ ${formatUsd(run.cost_usd_equivalent)} echivalent API`,
    ].filter((detail): detail is string => typeof detail === 'string');

    return (
        <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span className="inline-flex items-center gap-1.5 font-medium">
                <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${statusDotClasses[run.status]}`} />
                GitChronicle: {modeLabels[run.mode]}, {statusLabels[run.status]}
            </span>
            <time dateTime={run.started_at} className="text-info-ink/80">
                {formatDate(run.started_at)}
            </time>
            {details.length > 0 && <span className="text-info-ink/80">· {details.join(' · ')}</span>}
        </p>
    );
}
