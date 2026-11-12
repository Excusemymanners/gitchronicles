import CompactCard from '@/components/timeline/compact-card';
import { useI18n } from '@/hooks/use-i18n';
import { formatNumber } from '@/lib/format';
import { formatDay, formatMonth } from '@/lib/history-format';
import type { Activity } from '@/types/history';

type Props = {
    activity: Activity;
    /** Legătura spre tab-ul „Istoric”; fără ea cardul nu are legătură. */
    timelineHref?: string;
};

/**
 * Cardul „Activitate”: câte commit-uri au fost în fiecare din ultimele 12 luni (o bară pe lună,
 * luna curentă ultima), câți oameni au contribuit și când a fost ultimul commit.
 * O lună mai veche decât commit-urile importate nu are bară și e numită „neimportată”: nu știm cifra ei.
 * Fără niciun commit în aceste luni, graficul lipsește: cifra mare spune deja „0”.
 * Cititoarele de ecran primesc graficul ca tabel.
 */
export default function ActivityCard({ activity, timelineHref }: Props) {
    const { t, plural } = useI18n();
    const maxCount = Math.max(...activity.months.map((month) => month.commit_count ?? 0), 1);
    const firstMonth = activity.months[0];
    const lastMonth = activity.months[activity.months.length - 1];
    const firstKnownMonth = activity.months.find((month) => month.commit_count !== null);
    const hasCommitsInChart = activity.months.some((month) => (month.commit_count ?? 0) > 0);

    return (
        <CompactCard title={t('timeline.activity.title')} link={timelineHref ? { href: timelineHref, label: t('timeline.activity.viewHistory') } : undefined}>
            {/* Pe un card lat, cifrele stau în stânga și graficul în dreapta; pe unul îngust, unul sub altul. */}
            <div className="@container">
                <div className="grid gap-4 @lg:grid-cols-[minmax(0,17rem)_minmax(0,1fr)] @lg:items-end @lg:gap-8">
                    <div>
                        <Headline activity={activity} />

                        <dl className="mt-3 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt className="text-xs text-ink-subtle">{t('timeline.activity.contributors')}</dt>
                                <dd className="font-medium text-ink tabular-nums">{formatNumber(activity.contributor_count)}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-ink-subtle">{t('timeline.activity.lastCommit')}</dt>
                                <dd className="font-medium text-ink">
                                    <time dateTime={activity.last_commit_at}>{formatDay(activity.last_commit_at)}</time>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    {hasCommitsInChart && (
                        <div>
                            <div aria-hidden="true" className="flex h-16 items-end gap-0.5 border-b border-line">
                                {activity.months.map((month) => (
                                    <div key={month.month} className="group relative flex h-full flex-1 items-end justify-center">
                                        <span
                                            className="block w-full max-w-6 rounded-t-sm bg-accent group-hover:bg-accent-hover"
                                            style={{ height: month.commit_count ? `${Math.max((month.commit_count / maxCount) * 100, 6)}%` : 0 }}
                                        />
                                        <span className="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-sm border border-line bg-surface-raised px-1.5 py-0.5 text-xs whitespace-nowrap text-ink shadow-lg group-hover:block">
                                            {formatMonth(month.month, true)}: {month.commit_count === null ? t('timeline.activity.notImported') : formatNumber(month.commit_count)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                            <div aria-hidden="true" className="mt-1 flex justify-between text-xs text-ink-subtle">
                                <span>{formatMonth(firstMonth.month, true)}</span>
                                <span>{formatMonth(lastMonth.month, true)}</span>
                            </div>

                            <table className="sr-only">
                                <caption>{t('timeline.activity.tableCaption')}</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">{t('timeline.activity.month')}</th>
                                        <th scope="col">{t('timeline.activity.commits')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {activity.months.map((month) => (
                                        <tr key={month.month}>
                                            <th scope="row">{formatMonth(month.month)}</th>
                                            <td>{month.commit_count === null ? t('timeline.activity.notImported') : month.commit_count}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            <p className="mt-4 text-xs text-ink-subtle">
                {firstKnownMonth && firstKnownMonth !== firstMonth
                    ? t('timeline.activity.basedOnPartial', { commits: plural('commitsImported', activity.commit_count), month: formatMonth(firstKnownMonth.month) })
                    : t('timeline.activity.basedOn', { commits: plural('commitsImported', activity.commit_count) })}
            </p>
        </CompactCard>
    );
}

/**
 * Cifra mare: commit-urile din ultimele 12 luni, când le știm pe toate; altfel commit-urile
 * importate și data de la care încep (toate sunt atunci în ultimele 12 luni).
 */
function Headline({ activity }: { activity: Activity }) {
    const { rich } = useI18n();
    const count = activity.commits_last_year ?? activity.commit_count;
    const number = <span className="text-2xl font-semibold tracking-tight text-ink tabular-nums">{formatNumber(count)}</span>;

    return (
        <p className="text-sm text-ink-muted">
            {activity.commits_last_year !== null
                ? rich(count === 1 ? 'timeline.activity.lastYearOne' : 'timeline.activity.lastYear', { count: number })
                : rich(count === 1 ? 'timeline.activity.sinceOne' : 'timeline.activity.since', {
                      count: number,
                      date: formatDay(activity.first_commit_at),
                  })}
        </p>
    );
}
