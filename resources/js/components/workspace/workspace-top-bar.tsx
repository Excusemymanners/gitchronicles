import { Form, Link } from '@inertiajs/react';
import { AppLogoMark } from '@/components/app-logo';
import LanguageSwitch from '@/components/language-switch';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import LastUpdateLine from '@/components/repository/last-update';
import StatusBadge from '@/components/status-badge';
import ThemeToggle from '@/components/theme-toggle';
import { GitHubIcon, MenuIcon } from '@/components/workspace/icons';
import { shortSha } from '@/lib/format';
import type { LastUpdate, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    canDelete: boolean;
    canRetry: boolean;
    canCheckUpdates: boolean;
    canRegenerate: boolean;
    /** Ultima rulare terminată cu bine; `null` până la prima. */
    lastUpdate: LastUpdate | null;
    /** Deschide cuprinsul pe ecranele înguste; `null` pe ecranele late, unde cuprinsul e mereu vizibil. */
    onOpenChapters: (() => void) | null;
    isChaptersOpen: boolean;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const iconButtonClasses = `inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`;

/**
 * Bara de sus a spațiului de lucru: logo-ul (spre lista tuturor repository-urilor), numele repository-ului
 * și statusul lui; în dreapta ramura, „Actualizat la commitul … · acum 5 min.” cu „Verifică actualizări” și „Regenerează tot”,
 * GitHub, „Reîncearcă” după o eroare, „Șterge” pentru cine l-a adăugat și comutatorul de temă.
 * Pe telefon rămân doar butonul cuprinsului, numele, statusul și tema; restul apare sus în cuprins
 * (ChaptersDrawer), iar actualizarea deasupra capitolului (RepositoryLayout).
 */
export default function WorkspaceTopBar({
    repository,
    canDelete,
    canRetry,
    canCheckUpdates,
    canRegenerate,
    lastUpdate,
    onOpenChapters,
    isChaptersOpen,
}: Props) {
    return (
        <header className="flex h-14 shrink-0 items-center gap-2 border-b border-line bg-surface px-2 sm:gap-3 sm:px-4">
            {onOpenChapters && (
                <button
                    type="button"
                    onClick={onOpenChapters}
                    aria-label="Deschide cuprinsul"
                    aria-expanded={isChaptersOpen}
                    aria-haspopup="dialog"
                    className={iconButtonClasses}
                >
                    <MenuIcon />
                </button>
            )}

            <Link href="/" className={`flex shrink-0 items-center gap-2.5 rounded-md ${focusRing}`}>
                <AppLogoMark className="size-7" />
                <span className="font-brand text-base font-bold tracking-tight text-ink max-sm:sr-only">GitChronicle</span>
                <span className="sr-only">, toate repository-urile</span>
            </Link>

            <span aria-hidden="true" className="text-ink-subtle max-sm:hidden">
                /
            </span>

            {/* Pe telefon numele proprietarului ar împinge statusul afară din bară, așa că rămâne doar numele proiectului. */}
            <p className="min-w-0 truncate text-sm font-semibold text-ink" title={repository.full_name}>
                <span className="max-sm:sr-only">{repository.owner}/</span>
                {repository.name}
            </p>

            <StatusBadge status={repository.status} label={repository.status_label} />

            <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                {/* Ramura și commitul apar doar după prima clonare: până atunci nu le știm. */}
                <div className="flex items-center gap-4 pr-2 text-[0.8125rem] text-ink-muted max-lg:hidden">
                    {repository.default_branch && (
                        <span>
                            ramura <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs text-ink">{repository.default_branch}</code>
                        </span>
                    )}

                    {/* După prima rulare reușită: până la ce commit e documentat și de când. Înainte, doar commitul clonat. */}
                    {lastUpdate || canCheckUpdates || canRegenerate ? (
                        <LastUpdateLine repository={repository} lastUpdate={lastUpdate} canCheckUpdates={canCheckUpdates} canRegenerate={canRegenerate} />
                    ) : (
                        repository.last_sha && (
                            <span>
                                commit{' '}
                                <a
                                    href={`https://github.com/${repository.owner}/${repository.name}/commit/${repository.last_sha}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    title={`Ultimul commit clonat: ${repository.last_sha}`}
                                    className={`rounded-sm text-accent hover:text-accent-hover ${focusRing}`}
                                >
                                    <code className="rounded-sm bg-surface-2 px-1.5 py-0.5 font-mono text-xs">{shortSha(repository.last_sha)}</code>
                                    <span className="sr-only"> (ultimul commit clonat, pe GitHub, se deschide într-o filă nouă)</span>
                                </a>
                            </span>
                        )
                    )}
                </div>

                <a
                    href={repository.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    title="Deschide pe GitHub"
                    className={`${iconButtonClasses} max-lg:hidden`}
                >
                    <GitHubIcon />
                    <span className="sr-only">Deschide pe GitHub (se deschide într-o filă nouă)</span>
                </a>

                {/* „Reîncearcă” e aici pe ecranele late; pe telefon e în mesajul de eroare de deasupra capitolului. */}
                {canRetry && (
                    <Form action={`/repositories/${repository.id}/retry`} method="post" className="max-lg:hidden">
                        {({ processing }) => (
                            <button
                                type="submit"
                                disabled={processing}
                                className={`inline-flex cursor-pointer items-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                            >
                                {processing ? 'Se pornește…' : 'Reîncearcă'}
                            </button>
                        )}
                    </Form>
                )}

                {canDelete && (
                    <div className="max-lg:hidden">
                        <DeleteRepositoryButton repository={repository} />
                    </div>
                )}

                <LanguageSwitch />
                <ThemeToggle />
            </div>
        </header>
    );
}
