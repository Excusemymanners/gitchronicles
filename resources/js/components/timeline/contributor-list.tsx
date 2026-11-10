import { countLabel } from '@/lib/format';
import { formatDay } from '@/lib/history-format';
import type { Contributor } from '@/types/history';

type Props = {
    contributors: Contributor[];
    /** Câți contribuitori sunt în total (lista îi arată doar pe primii). */
    total: number;
};

/**
 * „Contribuitori”: oamenii care au scris commit-urile importate, cel cu cele mai multe commit-uri primul,
 * cu perioada în care au contribuit.
 */
export default function ContributorList({ contributors, total }: Props) {
    const hiddenCount = total - contributors.length;

    return (
        <>
            <ol className="divide-y divide-line-subtle">
                {contributors.map((contributor) => (
                    <li key={contributor.name} className="py-2 first:pt-0 last:pb-0">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="min-w-0 font-medium break-words text-ink">{contributor.name || 'autor necunoscut'}</span>
                            <span className="shrink-0 text-xs text-ink-subtle tabular-nums">
                                {countLabel(contributor.commit_count, 'commit', 'commit-uri')}
                            </span>
                        </div>
                        <p className="mt-0.5 text-xs text-ink-subtle">
                            {contributor.first_commit_at === contributor.last_commit_at
                                ? formatDay(contributor.first_commit_at)
                                : `${formatDay(contributor.first_commit_at)} – ${formatDay(contributor.last_commit_at)}`}
                        </p>
                    </li>
                ))}
            </ol>

            {hiddenCount > 0 && (
                <p className="mt-3 text-xs text-ink-subtle">Și încă {countLabel(hiddenCount, 'contribuitor', 'contribuitori')}.</p>
            )}
        </>
    );
}
