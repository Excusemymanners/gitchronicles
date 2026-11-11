import { countLabel, formatDate, formatNumber, shortSha } from '@/lib/format';
import { formatDuration, formatUsd } from '@/lib/history-format';
import type { AffectedModule, ProcessingMode, ProcessingRunItem, ProcessingRunStatus, ProcessingTrigger } from '@/types/history';

type Props = {
    /** Rulările care au procesat commit-ul, cea mai nouă prima. */
    runs: ProcessingRunItem[];
};

const modeLabels: Record<ProcessingMode, string> = {
    initial: 'prima procesare',
    incremental: 'actualizare incrementală',
    full: 'reprocesare completă',
};

const triggerLabels: Record<ProcessingTrigger, string> = {
    add: 'la adăugare',
    manual: 'manual',
    webhook: 'de webhook',
    schedule: 'de verificarea automată',
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

const viaLabels: Record<AffectedModule['via'], string> = {
    changed: 'fișiere schimbate',
    imports: 'importă dintr-un modul schimbat',
    imported: 'e importat de un modul schimbat',
};

const summaryClasses =
    'cursor-pointer rounded-sm font-medium text-info-ink hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Marcajul rulărilor GitChronicle pe commit-ul procesat: cea mai nouă rulare cu toate cifrele ei,
 * iar celelalte (dacă repository-ul a fost procesat de mai multe ori la același commit) într-o listă
 * care se deschide la cerere. O rulare „nimic nou” nu ascunde rularea care a lucrat la același commit:
 * rulările care au lucrat vin primele.
 */
export default function CommitRuns({ runs }: Props) {
    const [newest, ...older] = [...runs.filter((run) => run.status !== 'skipped'), ...runs.filter((run) => run.status === 'skipped')];

    return (
        <div className="mt-2 rounded-md border border-info-line bg-info-soft px-3 py-2 text-xs text-info-ink">
            <Run run={newest} />

            {older.length > 0 && (
                <details className="mt-1.5">
                    <summary className={summaryClasses}>
                        {older.length === 1 ? 'Încă o rulare' : `Încă ${countLabel(older.length, 'rulare', 'rulări')}`} pe acest commit
                    </summary>
                    <ul className="mt-1.5 flex flex-col gap-2 border-t border-info-line pt-1.5">
                        {older.map((run) => (
                            <li key={run.id}>
                                <Run run={run} />
                            </li>
                        ))}
                    </ul>
                </details>
            )}
        </div>
    );
}

/**
 * O rulare: felul ei, ce a pornit-o și cum s-a terminat, apoi cifrele (o cifră necunoscută nu apare),
 * modulele afectate (la cerere) și, la o actualizare incrementală, economia față de ultima procesare completă.
 */
function Run({ run }: { run: ProcessingRunItem }) {
    const isUpdate = run.mode !== 'initial';

    const numbers = [
        run.duration_ms !== null && `durată ${formatDuration(run.duration_ms)}`,
        isUpdate && run.changes !== null && changedFilesLabel(run),
        run.mode === 'incremental' &&
            run.status === 'succeeded' &&
            run.files_analyzed > 0 &&
            `${countLabel(run.files_analyzed, 'fișier analizat din nou', 'fișiere analizate din nou')}`,
        run.documents_regenerated + run.documents_kept > 0 &&
            `${countLabel(run.documents_regenerated, 'pagină rescrisă', 'pagini rescrise')}, ${formatNumber(run.documents_kept)} ${run.documents_kept === 1 ? 'păstrată' : 'păstrate'}`,
        (run.input_tokens > 0 || run.output_tokens > 0) &&
            `${formatNumber(run.input_tokens)} tokeni la intrare, ${formatNumber(run.output_tokens)} la ieșire`,
        run.cost_usd_equivalent !== null && `≈ ${formatUsd(run.cost_usd_equivalent)} echivalent API`,
    ].filter((detail): detail is string => typeof detail === 'string');

    const savings = savingsOf(run);

    return (
        <div className="flex flex-col gap-1">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span className="inline-flex items-center gap-1.5 font-medium">
                    <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${statusDotClasses[run.status]}`} />
                    GitChronicle: {modeLabels[run.mode]}, pornită {triggerLabels[run.trigger]}, {statusLabels[run.status]}
                </span>
                <time dateTime={run.started_at} className="text-info-ink/80">
                    {formatDate(run.started_at)}
                </time>
            </p>

            {numbers.length > 0 && <p className="text-info-ink/80">{numbers.join(' · ')}</p>}

            {run.changes?.full_reason && <p className="text-info-ink/80">De ce completă: {run.changes.full_reason}</p>}

            {run.changes !== null && run.changes.modules.length > 0 && <AffectedModules run={run} />}

            {savings.length > 0 && run.reference && (
                <p>
                    <span className="font-medium">Economie</span> față de procesarea completă
                    {run.reference.sha && (
                        <>
                            {' '}
                            de la commitul <code className="font-mono">{shortSha(run.reference.sha)}</code>
                        </>
                    )}
                    : {savings.join(' · ')}
                </p>
            )}
        </div>
    );
}

/** „12 fișiere schimbate (3 noi, 8 modificate, 1 șters)”; fără paranteză când rularea nu a putut compara commit-urile. */
function changedFilesLabel(run: ProcessingRunItem): string {
    const label = countLabel(run.files_changed, 'fișier schimbat', 'fișiere schimbate');
    const changes = run.changes;

    if (changes === null || changes.added === null || changes.modified === null || changes.deleted === null || run.files_changed === 0) {
        return label;
    }

    const parts = [
        changes.added > 0 && `${formatNumber(changes.added)} noi`,
        changes.modified > 0 && `${formatNumber(changes.modified)} modificate`,
        changes.deleted > 0 && `${formatNumber(changes.deleted)} șterse`,
    ].filter((part): part is string => typeof part === 'string');

    return `${label} (${parts.join(', ')})`;
}

/**
 * Economia unei actualizări incrementale, doar din cifre reale ale celor două rulări: timpul, paginile
 * rescrise și, când ambele rulări au folosit AI, tokenii. O comparație fără date nu apare.
 */
function savingsOf(run: ProcessingRunItem): string[] {
    const reference = run.reference;

    if (run.mode !== 'incremental' || run.status !== 'succeeded' || !reference) {
        return [];
    }

    const runTokens = run.input_tokens + run.output_tokens;
    const referenceTokens = reference.input_tokens + reference.output_tokens;

    return [
        run.duration_ms !== null &&
            reference.duration_ms !== null &&
            reference.duration_ms > 0 &&
            `${formatDuration(run.duration_ms)} în loc de ${formatDuration(reference.duration_ms)}`,
        reference.documents_regenerated > 0 &&
            `${countLabel(run.documents_regenerated, 'pagină rescrisă', 'pagini rescrise')} în loc de ${formatNumber(reference.documents_regenerated)}`,
        reference.files_analyzed > 0 &&
            run.files_analyzed > 0 &&
            `${countLabel(run.files_analyzed, 'fișier analizat', 'fișiere analizate')} în loc de ${formatNumber(reference.files_analyzed)}`,
        reference.context_characters > 0 &&
            `${formatNumber(run.context_characters)} caractere de context în loc de ${formatNumber(reference.context_characters)}`,
        runTokens > 0 && referenceTokens > 0 && `${formatNumber(runTokens)} tokeni în loc de ${formatNumber(referenceTokens)}`,
    ].filter((saving): saving is string => typeof saving === 'string');
}

/** Lista modulelor afectate, deschisă la cerere: întâi cele cu fișiere schimbate, apoi vecinii lor din graf. */
function AffectedModules({ run }: { run: ProcessingRunItem }) {
    const changes = run.changes;

    if (changes === null) {
        return null;
    }

    return (
        <details>
            <summary className={summaryClasses}>
                {countLabel(run.modules_affected, 'modul afectat', 'module afectate')} din {formatNumber(run.modules_total)}
            </summary>
            <ul className="mt-1.5 flex flex-col gap-1 border-t border-info-line pt-1.5">
                {changes.modules.map((module) => (
                    <li key={module.path} className="flex flex-wrap items-baseline gap-x-2">
                        <code className="font-mono break-all">{module.path === '/' ? 'rădăcina proiectului' : module.path}</code>
                        <span className="text-info-ink/80">
                            {module.via === 'changed' ? countLabel(module.files, 'fișier schimbat', 'fișiere schimbate') : viaLabels[module.via]}
                        </span>
                    </li>
                ))}
                {changes.modules_not_listed > 0 && (
                    <li className="text-info-ink/80">și încă {countLabel(changes.modules_not_listed, 'modul', 'module')}</li>
                )}
            </ul>
            {(changes.modules_added.length > 0 || changes.modules_removed.length > 0) && (
                <p className="mt-1.5 text-info-ink/80">
                    {changes.modules_added.length > 0 && <>Module noi: {changes.modules_added.join(', ')}. </>}
                    {changes.modules_removed.length > 0 && <>Module dispărute: {changes.modules_removed.join(', ')}.</>}
                </p>
            )}
        </details>
    );
}
