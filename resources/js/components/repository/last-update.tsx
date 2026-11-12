import { Form } from '@inertiajs/react';
import RegenerateButton from '@/components/repository/regenerate-button';
import { useI18n } from '@/hooks/use-i18n';
import { formatDate, formatRelative, shortSha } from '@/lib/format';
import type { LastUpdate, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** Ultima rulare terminată cu bine; `null` până la prima. */
    lastUpdate: LastUpdate | null;
    /** Adevărat când vizitatorul e autentificat și repository-ul e gata. */
    canCheckUpdates: boolean;
    /** Adevărat când vizitatorul e autentificat și repository-ul e gata sau a eșuat. */
    canRegenerate: boolean;
    className?: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * „Actualizat la commitul abc1234 · acum 5 min.” și butonul „Verifică actualizări”: până la ce commit e
 * documentat repository-ul și de când. Butonul pornește o actualizare incrementală (aduce commit-urile noi
 * și reface doar ce s-a schimbat); dacă pe GitHub nu e nimic nou, rularea se încheie imediat. Lângă el,
 * perechea lui, „Regenerează tot”: o procesare completă, care reface toate capitolele.
 * Fără nicio rulare terminată și fără butoane nu afișăm nimic: nu știm încă un commit.
 */
export default function LastUpdateLine({ repository, lastUpdate, canCheckUpdates, canRegenerate, className = '' }: Props) {
    const { t, rich } = useI18n();

    if (lastUpdate === null && !canCheckUpdates && !canRegenerate) {
        return null;
    }

    return (
        <div className={`flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[0.8125rem] text-ink-muted ${className}`}>
            {lastUpdate && (
                <p>
                    {rich('repository.update.updatedAtCommit', {
                        commit: (
                            <a
                                href={`https://github.com/${repository.owner}/${repository.name}/commit/${lastUpdate.sha}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                title={t('repository.update.lastProcessedCommit', { sha: lastUpdate.sha })}
                                className={`rounded-sm text-accent hover:text-accent-hover ${focusRing}`}
                            >
                                <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs">{shortSha(lastUpdate.sha)}</code>
                                <span className="sr-only"> {t('repository.update.commitLink')}</span>
                            </a>
                        ),
                    })}
                    {' · '}
                    <time dateTime={lastUpdate.finished_at} title={formatDate(lastUpdate.finished_at)}>
                        {formatRelative(lastUpdate.finished_at)}
                    </time>
                </p>
            )}

            {(canCheckUpdates || canRegenerate) && (
                <div className="flex items-center gap-2">
                    {canCheckUpdates && (
                        <Form action={`/repositories/${repository.id}/check-updates`} method="post" options={{ preserveScroll: true }}>
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={processing}
                                    title={t('repository.update.checkTitle')}
                                    className={`pressable inline-flex cursor-pointer items-center rounded-md bg-surface px-2.5 py-1 text-[0.8125rem] font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                                >
                                    {processing ? t('repository.update.starting') : t('repository.update.check')}
                                </button>
                            )}
                        </Form>
                    )}

                    {canRegenerate && <RegenerateButton repository={repository} />}
                </div>
            )}
        </div>
    );
}
