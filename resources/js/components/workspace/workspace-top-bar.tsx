import { Form, Link } from '@inertiajs/react';
import { useEffect, useId, useRef } from 'react';
import { AppLogoMark } from '@/components/app-logo';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import LanguageSwitch from '@/components/language-switch';
import LastUpdateLine from '@/components/repository/last-update';
import RegenerateButton, { menuItemClasses } from '@/components/repository/regenerate-button';
import StatusBadge from '@/components/status-badge';
import ThemeToggle from '@/components/theme-toggle';
import { ChevronIcon, GitHubIcon, MoreIcon, SearchIcon } from '@/components/workspace/icons';
import { shortcutLabel } from '@/components/workspace/shortcuts';
import { useI18n } from '@/hooks/use-i18n';
import type { LastUpdate, RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    canDelete: boolean;
    canRetry: boolean;
    canCheckUpdates: boolean;
    canRegenerate: boolean;
    /** The newest run that ended well; `null` until the first one. */
    lastUpdate: LastUpdate | null;
    /** Opens the command palette (Ctrl/Cmd+K). */
    onOpenPalette: () => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const iconButtonClasses = `pressable inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`;

/**
 * The top bar of a repository (56 px): the logo (back to all repositories), owner/name and the status only while
 * it is not ready. On the right, from 1024 px: the last-update sentence with "Check for updates", "Search" (the
 * command palette); then GitHub, the language, the theme and an overflow menu with the rarer actions —
 * "Regenerate everything" and "Delete", so a destructive action is never one click away from the theme toggle.
 * On narrower screens the last-update sentence and GitHub move into that menu.
 */
export default function WorkspaceTopBar({ repository, canDelete, canRetry, canCheckUpdates, canRegenerate, lastUpdate, onOpenPalette }: Props) {
    const { t } = useI18n();
    const hasUpdateLine = lastUpdate !== null || canCheckUpdates;

    return (
        <div className="flex h-14 items-center gap-2 bg-surface px-2 sm:gap-3 sm:px-4">
            <Link href="/" className={`flex shrink-0 items-center gap-2.5 rounded-md ${focusRing}`}>
                <AppLogoMark className="size-7" />
                <span className="font-brand text-base font-bold tracking-tight text-ink max-xl:sr-only">GitChronicle</span>
                <span className="sr-only">{t('workspace.allRepositories')}</span>
            </Link>

            <ChevronIcon className="size-3.5 shrink-0 text-ink-subtle max-sm:hidden" />

            <p className="min-w-0 truncate text-sm text-ink" title={repository.full_name}>
                <span className="text-ink-muted max-sm:sr-only">{repository.owner}/</span>
                <span className="font-semibold" style={{ viewTransitionName: `repo-title-${repository.id}` }}>
                    {repository.name}
                </span>
            </p>

            {repository.status !== 'ready' && <StatusBadge status={repository.status} label={repository.status_label} />}

            <div className="ml-auto flex shrink-0 items-center gap-1 sm:gap-2">
                {hasUpdateLine && <LastUpdateLine repository={repository} lastUpdate={lastUpdate} canCheckUpdates={canCheckUpdates} className="pr-2 max-lg:hidden" />}

                {canRetry && (
                    <Form action={`/repositories/${repository.id}/retry`} method="post" className="max-lg:hidden">
                        {({ processing }) => (
                            <button
                                type="submit"
                                disabled={processing}
                                className={`pressable inline-flex cursor-pointer items-center rounded-md bg-accent-strong px-3 py-1.5 text-sm font-semibold text-on-accent hover:bg-accent-strong-hover disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
                            >
                                {processing ? t('workspace.starting') : t('workspace.retry')}
                            </button>
                        )}
                    </Form>
                )}

                <button
                    type="button"
                    onClick={onOpenPalette}
                    aria-keyshortcuts="Control+K Meta+K"
                    className={`pressable inline-flex h-9 cursor-pointer items-center gap-2 rounded-md px-2.5 text-[0.8125rem] text-ink-muted ring-1 ring-line ring-inset hover:bg-surface-2 hover:text-ink max-lg:hidden ${focusRing}`}
                >
                    <SearchIcon className="size-3.5" />
                    {t('workspace.palette.open')}
                    <kbd className="font-sans text-xs text-ink-subtle">{shortcutLabel('K')}</kbd>
                </button>

                <a href={repository.url} target="_blank" rel="noopener noreferrer" title={t('workspace.openOnGitHub')} className={`${iconButtonClasses} max-sm:hidden`}>
                    <GitHubIcon />
                    <span className="sr-only">
                        {t('workspace.openOnGitHub')} {t('common.opensInNewTab')}
                    </span>
                </a>

                <LanguageSwitch />
                <ThemeToggle />

                <OverflowMenu
                    repository={repository}
                    lastUpdate={lastUpdate}
                    canCheckUpdates={canCheckUpdates}
                    canRegenerate={canRegenerate}
                    canDelete={canDelete}
                />
            </div>
        </div>
    );
}

type OverflowMenuProps = {
    repository: RepositoryItem;
    lastUpdate: LastUpdate | null;
    canCheckUpdates: boolean;
    canRegenerate: boolean;
    canDelete: boolean;
};

/**
 * The "More" menu: a native popover (popover="auto"), so the browser closes it on Esc or a click outside and
 * returns the focus to its button. It is placed under its button when it opens. On a wide screen it holds only
 * the rare actions; on a narrow one also the last-update sentence and GitHub, which do not fit in the bar.
 */
function OverflowMenu({ repository, lastUpdate, canCheckUpdates, canRegenerate, canDelete }: OverflowMenuProps) {
    const { t } = useI18n();
    const menuId = useId();
    const menuRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        const menu = menuRef.current;

        if (!menu) {
            return;
        }

        const placeUnderButton = (event: Event) => {
            const button = buttonRef.current;

            if ((event as ToggleEvent).newState !== 'open' || !button) {
                return;
            }

            const rect = button.getBoundingClientRect();
            menu.style.top = `${rect.bottom + 6}px`;
            menu.style.right = `${Math.max(8, window.innerWidth - rect.right)}px`;
        };

        menu.addEventListener('beforetoggle', placeUnderButton);

        return () => menu.removeEventListener('beforetoggle', placeUnderButton);
    }, []);

    const close = () => menuRef.current?.hidePopover();
    const hasUpdateLine = lastUpdate !== null || canCheckUpdates;
    const hasWideItems = canRegenerate || canDelete;

    return (
        <>
            <button
                ref={buttonRef}
                type="button"
                popoverTarget={menuId}
                aria-label={t('workspace.menu.label')}
                className={`${iconButtonClasses} ${hasWideItems ? '' : hasUpdateLine ? 'lg:hidden' : 'sm:hidden'}`}
            >
                <MoreIcon />
            </button>

            <div
                ref={menuRef}
                id={menuId}
                popover="auto"
                className="fixed inset-auto m-0 w-[min(18rem,calc(100vw-1rem))] rounded-lg border border-line bg-surface-raised p-1 text-ink shadow-float open:motion-safe:animate-rise"
            >
                {hasUpdateLine && (
                    <div className="border-b border-line px-3 pt-2 pb-3 lg:hidden">
                        <LastUpdateLine repository={repository} lastUpdate={lastUpdate} canCheckUpdates={canCheckUpdates} />
                    </div>
                )}

                <ul className="flex flex-col py-1">
                    <li className="sm:hidden">
                        <a href={repository.url} target="_blank" rel="noopener noreferrer" onClick={close} className={menuItemClasses}>
                            <GitHubIcon className="size-4 text-ink-muted" />
                            {t('workspace.openOnGitHub')}
                            <span className="sr-only"> {t('common.opensInNewTab')}</span>
                        </a>
                    </li>

                    {canRegenerate && (
                        <li>
                            <RegenerateButton repository={repository} variant="menu" onStart={close} />
                        </li>
                    )}

                    {canDelete && (
                        <li className="mt-1 border-t border-line pt-1">
                            <DeleteRepositoryButton repository={repository} onOpen={close} className={`${menuItemClasses} text-danger-ink`} />
                        </li>
                    )}
                </ul>
            </div>
        </>
    );
}
