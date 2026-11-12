import { useI18n } from '@/hooks/use-i18n';
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
    const { t, plural } = useI18n();
    const hiddenCount = total - contributors.length;

    return (
        <>
            <ol className="divide-y divide-line-subtle">
                {contributors.map((contributor) => (
                    <li key={contributor.name} className="py-2 first:pt-0 last:pb-0">
                        <div className="flex items-baseline justify-between gap-3 text-sm">
                            <span className="min-w-0 font-medium break-words text-ink">{contributor.name || t('timeline.contributor.unknownAuthor')}</span>
                            <span className="shrink-0 text-xs text-ink-subtle tabular-nums">
                                {plural('commits', contributor.commit_count)}
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
                <p className="mt-3 text-xs text-ink-subtle">{t('timeline.contributor.more', { contributors: plural('contributors', hiddenCount) })}</p>
            )}
        </>
    );
}
