import { Form } from '@inertiajs/react';
import { formatDate, formatRelative, shortSha } from '@/lib/format';
import type { LastUpdate, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** Ultima rulare terminată cu bine; `null` până la prima. */
    lastUpdate: LastUpdate | null;
    /** Adevărat când vizitatorul e autentificat și repository-ul e gata. */
    canCheckUpdates: boolean;
    className?: string;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * „Actualizat la commitul abc1234 · acum 5 min.” și butonul „Verifică actualizări”: până la ce commit e
 * documentat repository-ul și de când. Butonul pornește o actualizare incrementală (aduce commit-urile noi
 * și reface doar ce s-a schimbat); dacă pe GitHub nu e nimic nou, rularea se încheie imediat.
 * Fără nicio rulare terminată nu afișăm nimic: nu știm încă un commit.
 */
export default function LastUpdateLine({ repository, lastUpdate, canCheckUpdates, className = '' }: Props) {
    if (lastUpdate === null && !canCheckUpdates) {
        return null;
    }

    return (
        <div className={`flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[0.8125rem] text-ink-muted ${className}`}>
            {lastUpdate && (
                <p>
                    Actualizat la commitul{' '}
                    <a
                        href={`https://github.com/${repository.owner}/${repository.name}/commit/${lastUpdate.sha}`}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={`Ultimul commit procesat: ${lastUpdate.sha}`}
                        className={`rounded-sm text-accent hover:text-accent-hover ${focusRing}`}
                    >
                        <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs">{shortSha(lastUpdate.sha)}</code>
                        <span className="sr-only"> (pe GitHub, se deschide într-o filă nouă)</span>
                    </a>
                    {' · '}
                    <time dateTime={lastUpdate.finished_at} title={formatDate(lastUpdate.finished_at)}>
                        {formatRelative(lastUpdate.finished_at)}
                    </time>
                </p>
            )}

            {canCheckUpdates && (
                <Form action={`/repositories/${repository.id}/check-updates`} method="post" options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <button
                            type="submit"
                            disabled={processing}
                            className={`inline-flex cursor-pointer items-center rounded-md bg-surface px-2.5 py-1 text-[0.8125rem] font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                        >
                            {processing ? 'Se pornește…' : 'Verifică actualizări'}
                        </button>
                    )}
                </Form>
            )}
        </div>
    );
}
