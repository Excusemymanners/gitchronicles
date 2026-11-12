import { useI18n } from '@/hooks/use-i18n';
import type { TranslationKey } from '@/lang';
import { formatDate, formatNumber, shortSha } from '@/lib/format';
import { formatDuration, formatUsd } from '@/lib/history-format';
import { pluralize, translate } from '@/lib/i18n';
import type { AffectedModule, ProcessingMode, ProcessingRunItem, ProcessingRunStatus, ProcessingTrigger } from '@/types/history';

type Props = {
    /** Rulările care au procesat commit-ul, cea mai nouă prima. */
    runs: ProcessingRunItem[];
};

const modeLabels: Record<ProcessingMode, TranslationKey> = {
    initial: 'timeline.runs.mode.initial',
    incremental: 'timeline.runs.mode.incremental',
    full: 'timeline.runs.mode.full',
};

const triggerLabels: Record<ProcessingTrigger, TranslationKey> = {
    add: 'timeline.runs.trigger.add',
    manual: 'timeline.runs.trigger.manual',
    webhook: 'timeline.runs.trigger.webhook',
    schedule: 'timeline.runs.trigger.schedule',
};

const statusLabels: Record<ProcessingRunStatus, TranslationKey> = {
    running: 'timeline.runs.status.running',
    succeeded: 'timeline.runs.status.succeeded',
    failed: 'timeline.runs.status.failed',
    skipped: 'timeline.runs.status.skipped',
};

const statusDotClasses: Record<ProcessingRunStatus, string> = {
    running: 'bg-info motion-safe:animate-pulse',
    succeeded: 'bg-success',
    failed: 'bg-danger',
    skipped: 'bg-ink-subtle',
};

const viaLabels: Record<AffectedModule['via'], TranslationKey> = {
    changed: 'timeline.runs.via.changed',
    imports: 'timeline.runs.via.imports',
    imported: 'timeline.runs.via.imported',
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
    const { t } = useI18n();
    const [newest, ...older] = [...runs.filter((run) => run.status !== 'skipped'), ...runs.filter((run) => run.status === 'skipped')];

    return (
        <div className="mt-2 rounded-md border border-info-line bg-info-soft px-3 py-2 text-xs text-info-ink">
            <Run run={newest} />

            {older.length > 0 && (
                <details className="mt-1.5">
                    <summary className={summaryClasses}>
                        {older.length === 1 ? t('timeline.runs.oneMore') : t('timeline.runs.moreRuns', { runs: pluralize('runs', older.length) })}
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
    const { t, rich } = useI18n();
    const isUpdate = run.mode !== 'initial';

    const numbers = [
        run.duration_ms !== null && t('timeline.runs.duration', { duration: formatDuration(run.duration_ms) }),
        isUpdate && run.changes !== null && changedFilesLabel(run),
        run.mode === 'incremental' &&
            run.status === 'succeeded' &&
            run.files_analyzed > 0 &&
            pluralize('filesAnalyzedAgain', run.files_analyzed),
        run.documents_regenerated + run.documents_kept > 0 &&
            t('timeline.runs.pages', { rewritten: pluralize('pagesRewritten', run.documents_regenerated), kept: pluralize('pagesKept', run.documents_kept) }),
        (run.input_tokens > 0 || run.output_tokens > 0) &&
            t('timeline.runs.tokens', { input: pluralize('tokens', run.input_tokens), output: pluralize('tokens', run.output_tokens) }),
        run.cost_usd_equivalent !== null && t('timeline.runs.cost', { cost: formatUsd(run.cost_usd_equivalent) }),
    ].filter((detail): detail is string => typeof detail === 'string');

    const savings = savingsOf(run);

    return (
        <div className="flex flex-col gap-1">
            <p className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span className="inline-flex items-center gap-1.5 font-medium">
                    <span aria-hidden="true" className={`size-1.5 shrink-0 rounded-full ${statusDotClasses[run.status]}`} />
                    {t('timeline.runs.line', { mode: t(modeLabels[run.mode]), trigger: t(triggerLabels[run.trigger]), status: t(statusLabels[run.status]) })}
                </span>
                <time dateTime={run.started_at} className="text-info-ink">
                    {formatDate(run.started_at)}
                </time>
            </p>

            {numbers.length > 0 && <p className="text-info-ink">{numbers.join(' · ')}</p>}

            {run.changes?.full_reason && <p className="text-info-ink">{t('timeline.runs.whyFull', { reason: run.changes.full_reason })}</p>}

            {run.changes !== null && run.changes.modules.length > 0 && <AffectedModules run={run} />}

            {savings.length > 0 && run.reference && (
                <p>
                    {run.reference.sha
                        ? rich('timeline.runs.savingsAtCommit', {
                              sha: <code className="font-mono">{shortSha(run.reference.sha)}</code>,
                              savings: savings.join(' · '),
                          })
                        : t('timeline.runs.savings', { savings: savings.join(' · ') })}
                </p>
            )}
        </div>
    );
}

/** „12 fișiere schimbate (3 noi, 8 modificate, 1 șters)”; fără paranteză când rularea nu a putut compara commit-urile. */
function changedFilesLabel(run: ProcessingRunItem): string {
    const label = pluralize('filesChanged', run.files_changed);
    const changes = run.changes;

    if (changes === null || changes.added === null || changes.modified === null || changes.deleted === null || run.files_changed === 0) {
        return label;
    }

    const parts = [
        changes.added > 0 && translate('timeline.runs.newFiles', { count: formatNumber(changes.added) }),
        changes.modified > 0 && translate('timeline.runs.modifiedFiles', { count: formatNumber(changes.modified) }),
        changes.deleted > 0 && translate('timeline.runs.deletedFiles', { count: formatNumber(changes.deleted) }),
    ].filter((part): part is string => typeof part === 'string');

    return translate('timeline.runs.changedParts', { files: label, parts: parts.join(', ') });
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
            translate('timeline.runs.insteadOf', { now: formatDuration(run.duration_ms), before: formatDuration(reference.duration_ms) }),
        reference.documents_regenerated > 0 &&
            translate('timeline.runs.insteadOf', {
                now: pluralize('pagesRewritten', run.documents_regenerated),
                before: formatNumber(reference.documents_regenerated),
            }),
        reference.files_analyzed > 0 &&
            run.files_analyzed > 0 &&
            translate('timeline.runs.insteadOf', { now: pluralize('filesAnalyzed', run.files_analyzed), before: formatNumber(reference.files_analyzed) }),
        reference.context_characters > 0 &&
            translate('timeline.runs.contextCharacters', {
                now: formatNumber(run.context_characters),
                before: formatNumber(reference.context_characters),
            }),
        runTokens > 0 &&
            referenceTokens > 0 &&
            translate('timeline.runs.tokensInsteadOf', { now: formatNumber(runTokens), before: formatNumber(referenceTokens) }),
    ].filter((saving): saving is string => typeof saving === 'string');
}

/** Lista modulelor afectate, deschisă la cerere: întâi cele cu fișiere schimbate, apoi vecinii lor din graf. */
function AffectedModules({ run }: { run: ProcessingRunItem }) {
    const { t, plural } = useI18n();
    const changes = run.changes;

    if (changes === null) {
        return null;
    }

    return (
        <details>
            <summary className={summaryClasses}>
                {t('timeline.runs.affected', { affected: plural('modulesAffected', run.modules_affected), total: formatNumber(run.modules_total) })}
            </summary>
            <ul className="mt-1.5 flex flex-col gap-1 border-t border-info-line pt-1.5">
                {changes.modules.map((module) => (
                    <li key={module.path} className="flex flex-wrap items-baseline gap-x-2">
                        <code className="font-mono break-all">{module.path === '/' ? t('diagrams.projectRoot') : module.path}</code>
                        <span className="text-info-ink">
                            {module.via === 'changed' ? plural('filesChanged', module.files) : t(viaLabels[module.via])}
                        </span>
                    </li>
                ))}
                {changes.modules_not_listed > 0 && (
                    <li className="text-info-ink">
                        {t('timeline.runs.moreModules', { count: formatNumber(changes.modules_not_listed), modules: plural('modules', changes.modules_not_listed) })}
                    </li>
                )}
            </ul>
            {(changes.modules_added.length > 0 || changes.modules_removed.length > 0) && (
                <p className="mt-1.5 text-info-ink">
                    {changes.modules_added.length > 0 && `${t('timeline.runs.newModules', { names: changes.modules_added.join(', ') })} `}
                    {changes.modules_removed.length > 0 && t('timeline.runs.removedModules', { names: changes.modules_removed.join(', ') })}
                </p>
            )}
        </details>
    );
}
