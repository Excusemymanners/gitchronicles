import { Fragment } from 'react';
import { countLabel, formatNumber } from '@/lib/format';
import { fileUrl, formatDay } from '@/lib/history-format';
import type { RepositoryItem } from '@/types';
import type { Hotspot } from '@/types/history';

type Props = {
    repository: RepositoryItem;
    hotspots: Hotspot[];
    /** `true` pe cardul compact: doar calea și numărul de commit-uri. */
    compact?: boolean;
};

/**
 * Lista „Zone fierbinți”: fișierele schimbate de cele mai multe commit-uri, cu o bară pentru numărul lor
 * (față de primul fișier). Lista completă adaugă liniile schimbate, câți oameni le-au schimbat, data
 * ultimei schimbări și mărimea de acum; un fișier care există acum are legătură spre GitHub.
 */
export default function HotspotList({ repository, hotspots, compact = false }: Props) {
    const maxCommits = Math.max(...hotspots.map((hotspot) => hotspot.commit_count), 1);

    return (
        <ol className="flex flex-col gap-3">
            {hotspots.map((hotspot) => {
                const githubUrl =
                    !compact && hotspot.lines !== null && repository.last_sha !== null
                        ? fileUrl(repository.owner, repository.name, repository.last_sha, hotspot.path)
                        : null;

                return (
                    <li key={hotspot.path} className="text-sm">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3">
                            {githubUrl ? (
                                <a
                                    href={githubUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="min-w-0 rounded-sm font-mono text-xs break-words text-accent hover:text-accent-hover hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
                                >
                                    <PathWithBreaks path={hotspot.path} />
                                    <span className="sr-only"> (fișierul pe GitHub, se deschide într-o filă nouă)</span>
                                </a>
                            ) : (
                                <code className="min-w-0 font-mono text-xs break-words text-ink">
                                    <PathWithBreaks path={hotspot.path} />
                                </code>
                            )}
                            {compact && (
                                <span className="shrink-0 text-xs text-ink-subtle tabular-nums">{countLabel(hotspot.commit_count, 'commit', 'commit-uri')}</span>
                            )}
                        </div>

                        <span aria-hidden="true" className="mt-1 block h-1.5 rounded-full bg-surface-2">
                            <span
                                className="block h-1.5 rounded-full bg-warning"
                                style={{ width: `${Math.max((hotspot.commit_count / maxCommits) * 100, 2)}%` }}
                            />
                        </span>

                        {!compact && <HotspotDetails hotspot={hotspot} />}
                    </li>
                );
            })}
        </ol>
    );
}

/** O cale de fișier care, pe un rând îngust, se rupe după „/”, nu la mijlocul unui nume. */
function PathWithBreaks({ path }: { path: string }) {
    return path.split('/').map((segment, index) => (
        <Fragment key={index}>
            {index > 0 && (
                <>
                    /<wbr />
                </>
            )}
            {segment}
        </Fragment>
    ));
}

/** Rândul de sub bară: doar cifrele pe care le știm. */
function HotspotDetails({ hotspot }: { hotspot: Hotspot }) {
    const details = [
        countLabel(hotspot.commit_count, 'commit', 'commit-uri'),
        hotspot.insertions !== null && hotspot.deletions !== null && `+${formatNumber(hotspot.insertions)} −${formatNumber(hotspot.deletions)} linii`,
        countLabel(hotspot.author_count, 'autor', 'autori'),
        `ultima schimbare ${formatDay(hotspot.last_changed_at)}`,
        hotspot.lines !== null && `${countLabel(hotspot.lines, 'linie', 'linii')} acum`,
    ].filter((detail): detail is string => typeof detail === 'string');

    return (
        <p className="mt-1 text-xs text-ink-subtle">
            <span className="font-medium text-ink-muted">{details[0]}</span>
            {details.slice(1).map((detail) => ` · ${detail}`)}
        </p>
    );
}
