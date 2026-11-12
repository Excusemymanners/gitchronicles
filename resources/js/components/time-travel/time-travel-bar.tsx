import { Link, router } from '@inertiajs/react';
import { useId } from 'react';
import { useI18n } from '@/hooks/use-i18n';
import { shortSha } from '@/lib/format';
import { formatDay } from '@/lib/history-format';
import { translate } from '@/lib/i18n';
import type { ProcessedCommit } from '@/types/history';

type Props = {
    /** Commit-urile procesate, de la cel mai vechi la cel mai nou. */
    commits: ProcessedCommit[];
    /** Commit-ul ales; `null` = versiunea curentă. */
    selected: string | null;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const stepButtonClasses = `inline-flex cursor-pointer items-center rounded-md bg-surface px-2.5 py-1.5 text-sm font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`;

/** Data afișată pentru un commit: a commit-ului, dacă îl avem importat, altfel a procesării. */
function commitDay(commit: ProcessedCommit): string | null {
    const date = commit.authored_at ?? commit.processed_at;

    return date === null ? null : formatDay(date);
}

/** „abc1234 · 10 nov. 2026 · mesajul commit-ului”, pentru lista de alegere. */
function commitLabel(commit: ProcessedCommit): string {
    const parts = [shortSha(commit.sha), commitDay(commit), commit.subject].filter((part): part is string => Boolean(part));

    return parts.join(' · ') + (commit.is_current ? translate('timeTravel.current') : '');
}

/**
 * Călătoria în timp: alegi un commit procesat (din listă, cu butoanele „Mai vechi” / „Mai nou” sau cu
 * cursorul) și pagina arată cum era atunci. Alegerea stă în adresă (`?sha=`), deci se poate trimite cuiva;
 * cel mai nou commit înseamnă versiunea curentă, fără `sha`. Cu un singur commit procesat nu avem unde călători.
 */
export default function TimeTravelBar({ commits, selected }: Props) {
    const { t, rich } = useI18n();
    const selectId = useId();

    if (commits.length < 2) {
        return null;
    }

    const currentIndex = commits.length - 1;
    const selectedIndex = selected === null ? currentIndex : Math.max(0, commits.findIndex((commit) => commit.sha === selected));
    const selectedCommit = commits[selectedIndex];

    // Rămânem pe aceeași pagină (și pe aceeași secțiune), schimbăm doar commit-ul din adresă.
    function travelTo(index: number) {
        const commit = commits[index];

        router.get(window.location.pathname, commit.is_current ? {} : { sha: commit.sha }, { preserveScroll: true, preserveState: true });
    }

    return (
        <section aria-label={t('timeTravel.title')} className="mb-6 rounded-lg border border-line bg-surface p-3 shadow-xs sm:p-4">
            <div className="flex flex-wrap items-end gap-x-3 gap-y-2">
                <div className="min-w-0 flex-1 basis-64">
                    <label htmlFor={selectId} className="block text-xs font-semibold tracking-wide text-ink-subtle uppercase">
                        {t('timeTravel.title')}
                    </label>
                    <select
                        id={selectId}
                        value={selectedCommit.sha}
                        onChange={(event) => travelTo(commits.findIndex((commit) => commit.sha === event.target.value))}
                        className="mt-1 block w-full rounded-md border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:outline-2 focus:-outline-offset-1 focus:outline-accent"
                    >
                        {/* Cele mai noi primele, ca într-un istoric. */}
                        {[...commits].reverse().map((commit) => (
                            <option key={commit.sha} value={commit.sha}>
                                {commitLabel(commit)}
                            </option>
                        ))}
                    </select>
                </div>

                <div className="flex gap-2">
                    <button type="button" onClick={() => travelTo(selectedIndex - 1)} disabled={selectedIndex === 0} className={stepButtonClasses}>
                        <span aria-hidden="true">‹&nbsp;</span>{t('timeTravel.older')}
                    </button>
                    <button
                        type="button"
                        onClick={() => travelTo(selectedIndex + 1)}
                        disabled={selectedIndex === currentIndex}
                        className={stepButtonClasses}
                    >
                        {t('timeTravel.newer')}
                        <span aria-hidden="true">&nbsp;›</span>
                    </button>
                </div>
            </div>

            <input
                type="range"
                min={0}
                max={currentIndex}
                step={1}
                value={selectedIndex}
                onChange={(event) => travelTo(Number(event.target.value))}
                aria-label={t('timeTravel.range')}
                aria-valuetext={commitLabel(selectedCommit)}
                className={`mt-3 block w-full cursor-pointer accent-accent-strong ${focusRing}`}
            />

            {selected !== null && (
                <p role="status" className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning-ink">
                    <span>
                        {rich(commitDay(selectedCommit) ? 'timeTravel.viewingOldDated' : 'timeTravel.viewingOld', {
                            sha: <code className="font-mono">{shortSha(selectedCommit.sha)}</code>,
                            date: commitDay(selectedCommit),
                        })}
                    </span>
                    <Link
                        href={window.location.pathname}
                        preserveScroll
                        className={`rounded-sm font-semibold underline underline-offset-2 hover:no-underline ${focusRing}`}
                    >
                        {t('timeTravel.backToCurrent')}
                    </Link>
                </p>
            )}
        </section>
    );
}
