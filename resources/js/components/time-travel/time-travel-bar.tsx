import { Link, router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { ChevronLeftIcon, ChevronRightIcon } from '@/components/docs/docs-icons';
import { useI18n } from '@/hooks/use-i18n';
import { shortSha } from '@/lib/format';
import { formatDay } from '@/lib/history-format';
import { translate } from '@/lib/i18n';
import type { ProcessedCommit } from '@/types/history';

type Props = {
    /** Processed commits, from the oldest to the newest. */
    commits: ProcessedCommit[];
    /** The chosen commit; `null` = the current version. */
    selected: string | null;
};

/** Up to this many commits get a ring each; with more, the slider stays. */
const MAX_RING_COMMITS = 24;

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const stepButtonClasses = `pressable inline-flex cursor-pointer items-center gap-1 rounded-md bg-surface px-2.5 py-1.5 text-sm font-medium text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`;

/** The date shown for a commit: the commit's own, if we imported it, otherwise the processing date. */
function commitDay(commit: ProcessedCommit): string | null {
    const date = commit.authored_at ?? commit.processed_at;

    return date === null ? null : formatDay(date);
}

/** "abc1234 · Nov 10, 2026 · commit message", for the list and the rail tooltips. */
function commitLabel(commit: ProcessedCommit): string {
    const parts = [shortSha(commit.sha), commitDay(commit), commit.subject].filter((part): part is string => Boolean(part));

    return parts.join(' · ') + (commit.is_current ? translate('timeTravel.current') : '');
}

/**
 * Time travel: pick a processed commit (on the rail, from the list, with the "Older" / "Newer" buttons or the
 * slider) and the page shows how it was then. The choice lives in the address (`?sha=`), so it can be shared;
 * the newest commit means the current version, without `sha`. With a single processed commit there is nowhere to go.
 * Collapsed it is one line; it opens by itself while an old version is on screen.
 */
export default function TimeTravelBar({ commits, selected }: Props) {
    const { t, rich } = useI18n();
    const selectId = useId();
    const [isOpen, setIsOpen] = useState(selected !== null);

    if (commits.length < 2) {
        return null;
    }

    const currentIndex = commits.length - 1;
    const selectedIndex = selected === null ? currentIndex : Math.max(0, commits.findIndex((commit) => commit.sha === selected));
    const selectedCommit = commits[selectedIndex];
    const showRail = commits.length <= MAX_RING_COMMITS;

    // Stay on the same page (and the same section), only the commit in the address changes.
    function travelTo(index: number) {
        const commit = commits[index];

        router.get(window.location.pathname, commit.is_current ? {} : { sha: commit.sha }, { preserveScroll: true, preserveState: true });
    }

    return (
        <section aria-label={t('timeTravel.title')} className="mb-8 border-b border-line pb-4">
            <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                {selected === null ? (
                    <p className="text-[0.8125rem] text-ink-subtle">
                        {rich('timeTravel.collapsed', { sha: <code className="font-mono">{shortSha(selectedCommit.sha)}</code> })}
                    </p>
                ) : (
                    <p role="status" className="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-warning-line bg-warning-soft px-3 py-2 text-sm text-warning-ink">
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

                <button
                    type="button"
                    onClick={() => setIsOpen(!isOpen)}
                    aria-expanded={isOpen}
                    className={`pressable cursor-pointer rounded-md px-2 py-1 text-sm font-medium text-accent hover:text-accent-hover ${focusRing}`}
                >
                    {isOpen ? t('timeTravel.close') : t('timeTravel.open')}
                </button>
            </div>

            {isOpen && (
                <div className="mt-4 animate-fade-in">
                    <div className="flex flex-wrap items-end gap-x-3 gap-y-2">
                        <div className="min-w-0 flex-1 basis-64">
                            <label htmlFor={selectId} className="block text-[0.8125rem] font-medium text-ink-subtle">
                                {t('timeTravel.title')}
                            </label>
                            <select
                                id={selectId}
                                value={selectedCommit.sha}
                                onChange={(event) => travelTo(commits.findIndex((commit) => commit.sha === event.target.value))}
                                className="mt-1 block w-full rounded-md border border-line-strong bg-surface px-2 py-1.5 text-sm text-ink focus:outline-2 focus:-outline-offset-1 focus:outline-accent"
                            >
                                {/* Newest first, like a history. */}
                                {[...commits].reverse().map((commit) => (
                                    <option key={commit.sha} value={commit.sha}>
                                        {commitLabel(commit)}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="flex gap-2">
                            <button type="button" onClick={() => travelTo(selectedIndex - 1)} disabled={selectedIndex === 0} className={stepButtonClasses}>
                                <ChevronLeftIcon />
                                {t('timeTravel.older')}
                            </button>
                            <button type="button" onClick={() => travelTo(selectedIndex + 1)} disabled={selectedIndex === currentIndex} className={stepButtonClasses}>
                                {t('timeTravel.newer')}
                                <ChevronRightIcon />
                            </button>
                        </div>
                    </div>

                    {showRail ? (
                        <CommitRail commits={commits} selectedIndex={selectedIndex} onTravel={travelTo} />
                    ) : (
                        <input
                            type="range"
                            min={0}
                            max={currentIndex}
                            step={1}
                            value={selectedIndex}
                            onChange={(event) => travelTo(Number(event.target.value))}
                            aria-label={t('timeTravel.range')}
                            aria-valuetext={commitLabel(selectedCommit)}
                            className={`mt-4 block w-full cursor-pointer accent-accent-strong ${focusRing}`}
                        />
                    )}
                </div>
            )}
        </section>
    );
}

/**
 * The rail: a horizontal line with a ring for every processed commit; the chosen one is filled.
 * Hovering or focusing a ring shows its SHA, date and message (a CSS tooltip, so the keyboard gets it too).
 */
function CommitRail({ commits, selectedIndex, onTravel }: { commits: ProcessedCommit[]; selectedIndex: number; onTravel: (index: number) => void }) {
    const { t } = useI18n();

    return (
        <div role="group" aria-label={t('timeTravel.rail')} className="relative mt-4 flex items-center justify-between px-1">
            <span aria-hidden="true" className="absolute inset-x-3 top-1/2 h-px -translate-y-1/2 bg-line-strong" />

            {commits.map((commit, index) => {
                const isSelected = index === selectedIndex;
                // The tooltips at both ends line up with the edge so they do not leave the column.
                const tooltipPlacement = index < 3 ? 'left-0' : index >= commits.length - 3 ? 'right-0' : 'left-1/2 -translate-x-1/2';

                return (
                    <button
                        key={commit.sha}
                        type="button"
                        onClick={() => onTravel(index)}
                        aria-label={commitLabel(commit)}
                        aria-current={isSelected ? 'true' : undefined}
                        className={`group/ring relative z-10 inline-flex cursor-pointer items-center justify-center rounded-full p-1.5 ${focusRing}`}
                    >
                        <span
                            className={`block size-3.5 rounded-full border-2 transition-colors duration-(--motion-fast) group-hover/ring:border-accent-hover ${
                                isSelected ? 'border-accent bg-accent' : 'border-line-strong bg-canvas'
                            }`}
                        />
                        <span
                            role="tooltip"
                            className={`pointer-events-none absolute bottom-full z-20 mb-1 w-max max-w-64 rounded-md border border-line bg-surface-raised px-2.5 py-1.5 text-left text-[0.8125rem] text-ink opacity-0 shadow-float group-hover/ring:opacity-100 group-focus-visible/ring:opacity-100 ${tooltipPlacement}`}
                        >
                            <span className="block font-mono">{shortSha(commit.sha)}</span>
                            {commitDay(commit) && <span className="block text-ink-subtle">{commitDay(commit)}</span>}
                            {commit.subject && <span className="block truncate">{commit.subject}</span>}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
