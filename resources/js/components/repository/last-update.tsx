import { Form } from '@inertiajs/react';
import { RefreshIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate, formatRelative, shortSha } from '@/lib/format';
import type { LastUpdate, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** The newest run that ended well; `null` until the first one. */
    lastUpdate: LastUpdate | null;
    /** True when the visitor is logged in and the repository is ready. */
    canCheckUpdates: boolean;
    className?: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * The living-documentation promise in one sentence: "Updated at commit abc1234, 5 minutes ago." or, when the
 * last check found nothing new, "Up to date with GitHub, checked 5 minutes ago." Next to it "Check for updates"
 * starts an incremental update (new commits only); its icon spins while the update runs.
 * Without a finished run and without the button there is nothing to say: no commit is known yet.
 */
export default function LastUpdateLine({ repository, lastUpdate, canCheckUpdates, className = '' }: Props) {
    const { t, rich } = useI18n();

    if (lastUpdate === null && !canCheckUpdates) {
        return null;
    }

    const time = lastUpdate && (
        <time dateTime={lastUpdate.finished_at} title={formatDate(lastUpdate.finished_at)}>
            {formatRelative(lastUpdate.finished_at)}
        </time>
    );

    return (
        <div className={`flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[0.8125rem] text-ink-muted ${className}`}>
            {lastUpdate && (
                <p>
                    {lastUpdate.nothing_new
                        ? rich('repository.update.upToDate', { time })
                        : rich('repository.update.sentence', {
                              time,
                              commit: (
                                  <a
                                      href={`https://github.com/${repository.owner}/${repository.name}/commit/${lastUpdate.sha}`}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      title={t('repository.update.lastProcessedCommit', { sha: lastUpdate.sha })}
                                      className={`rounded-sm font-mono text-accent hover:text-accent-hover hover:underline ${focusRing}`}
                                  >
                                      {shortSha(lastUpdate.sha)}
                                      <span className="sr-only"> {t('repository.update.commitLink')}</span>
                                  </a>
                              ),
                          })}
                </p>
            )}

            {canCheckUpdates && (
                <Form action={`/repositories/${repository.id}/check-updates`} method="post" options={{ preserveScroll: true }}>
                    {({ processing }) => {
                        const isChecking = processing || repository.status === 'updating';

                        return (
                            <button
                                type="submit"
                                disabled={isChecking}
                                title={t('repository.update.checkTitle')}
                                className={`pressable inline-flex cursor-pointer items-center gap-1.5 rounded-md bg-surface px-2.5 py-1 text-[0.8125rem] font-medium whitespace-nowrap text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-70 ${focusRing}`}
                            >
                                <RefreshIcon className={`size-3.5 ${isChecking ? 'motion-safe:animate-spin' : ''}`} />
                                {isChecking ? t('repository.update.checking') : t('repository.update.check')}
                            </button>
                        );
                    }}
                </Form>
            )}
        </div>
    );
}
