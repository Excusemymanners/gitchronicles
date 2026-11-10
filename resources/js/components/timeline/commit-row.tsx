import CommitRuns from '@/components/timeline/commit-runs';
import LineChanges from '@/components/timeline/line-changes';
import { countLabel, formatDate, shortSha } from '@/lib/format';
import { commitUrl } from '@/lib/history-format';
import type { RepositoryItem } from '@/types';
import type { CommitItem, ProcessingRunItem } from '@/types/history';

type Props = {
    repository: RepositoryItem;
    commit: CommitItem;
    /** Rulările GitChronicle care au procesat acest commit (de obicei niciuna). */
    runs: ProcessingRunItem[];
};

/**
 * Un commit pe linia timpului: un punct pe linie, mesajul, autorul, data, SHA-ul scurt (legătură spre
 * GitHub) și liniile schimbate. Un commit procesat de GitChronicle are un punct mai mare și marcajul rulării.
 */
export default function CommitRow({ repository, commit, runs }: Props) {
    const isProcessed = runs.length > 0;

    return (
        <li className="relative pb-5 pl-5 last:pb-1">
            <span
                aria-hidden="true"
                className={`absolute top-1.5 rounded-full ring-4 ring-canvas ${
                    isProcessed ? '-left-[6.5px] size-3 bg-info' : commit.is_merge ? '-left-[4.5px] size-2 bg-ink-subtle' : '-left-[4.5px] size-2 bg-accent'
                }`}
            />

            <p className="text-sm font-medium break-words text-ink">
                {commit.subject !== '' ? commit.subject : <span className="text-ink-subtle italic">(fără mesaj)</span>}
            </p>

            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-subtle">
                <span className="font-medium text-ink-muted">{commit.author_name !== '' ? commit.author_name : 'autor necunoscut'}</span>

                <time dateTime={commit.authored_at}>{formatDate(commit.authored_at)}</time>

                <a
                    href={commitUrl(repository.owner, repository.name, commit.sha)}
                    target="_blank"
                    rel="noopener noreferrer"
                    title={commit.sha}
                    className="rounded-sm text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                >
                    <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono">{shortSha(commit.sha)}</code>
                    <span className="sr-only"> (commit-ul pe GitHub, se deschide într-o filă nouă)</span>
                </a>

                {commit.is_merge && (
                    <span className="rounded-full bg-surface-2 px-2 py-0.5 font-medium text-ink-muted ring-1 ring-line ring-inset">
                        îmbinare de ramuri
                    </span>
                )}

                {commit.files_changed !== null && <span>{countLabel(commit.files_changed, 'fișier', 'fișiere')}</span>}

                {commit.insertions !== null && commit.deletions !== null && (
                    <LineChanges insertions={commit.insertions} deletions={commit.deletions} />
                )}
            </div>

            {isProcessed && <CommitRuns runs={runs} />}
        </li>
    );
}
