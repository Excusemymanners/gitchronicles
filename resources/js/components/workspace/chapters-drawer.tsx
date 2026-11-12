import { useEffect, useRef } from 'react';
import type { MouseEvent } from 'react';
import DeleteRepositoryButton from '@/components/delete-repository-button';
import ChaptersNav from '@/components/workspace/chapters-nav';
import { CloseIcon, GitHubIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import { shortSha } from '@/lib/format';
import type { RepositoryItem, WorkspaceGroup } from '@/types';

type Props = {
    repository: RepositoryItem;
    groups: WorkspaceGroup[];
    canDelete: boolean;
    isOpen: boolean;
    onClose: () => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Cuprinsul pe ecranele înguste: un sertar care intră din stânga, peste pagină. E un <dialog> deschis cu
 * showModal(), așa că browserul face singur partea grea: focusul rămâne în sertar, Escape îl închide,
 * iar restul paginii nu poate fi atins cât e deschis. Se închide și la un clic pe fundal sau pe un capitol.
 *
 * Sus are ce nu încape în bara de sus pe telefon: ramura, ultimul commit, GitHub și „Șterge”.
 */
export default function ChaptersDrawer({ repository, groups, canDelete, isOpen, onClose }: Props) {
    const { t, rich } = useI18n();
    const dialogRef = useRef<HTMLDialogElement>(null);

    // La deschidere, focusul merge pe capitolul curent (adus în vedere), ca Tab să continue prin cuprins.
    useEffect(() => {
        const dialog = dialogRef.current;

        if (!dialog) {
            return;
        }

        if (isOpen && !dialog.open) {
            dialog.showModal();

            const activeLink = dialog.querySelector<HTMLElement>('[aria-current="page"]');
            activeLink?.scrollIntoView({ block: 'center' });
            activeLink?.focus();
        } else if (!isOpen && dialog.open) {
            dialog.close();
        }
    }, [isOpen]);

    // Un clic chiar pe <dialog> (nu pe conținutul lui) e un clic pe fundalul din jurul sertarului.
    // Un clic pe o legătură deschide un capitol: sertarul se închide ca pagina să se vadă.
    function handleClick(event: MouseEvent<HTMLDialogElement>) {
        const target = event.target as HTMLElement;

        if (target === dialogRef.current || target.closest('a') !== null) {
            onClose();
        }
    }

    return (
        <dialog
            ref={dialogRef}
            aria-label={t('workspace.contents')}
            onClose={onClose}
            onClick={handleClick}
            className="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-[min(20rem,88vw)] max-w-none overflow-hidden border-r border-line bg-surface-raised p-0 text-ink backdrop:bg-canvas/80 backdrop:backdrop-blur-sm open:flex open:flex-col motion-safe:transition-transform motion-safe:duration-200 starting:open:-translate-x-full"
        >
            <div className="flex shrink-0 items-start gap-3 border-b border-line px-4 py-3">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold text-ink">{repository.full_name}</p>

                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-muted">
                        {repository.default_branch && (
                            <span>
                                {rich('workspace.branch', {
                                    branch: <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono text-ink">{repository.default_branch}</code>,
                                })}
                            </span>
                        )}

                        {repository.last_sha && (
                            <span>
                                {rich('workspace.commit', {
                                    sha: (
                                        <a
                                            href={`https://github.com/${repository.owner}/${repository.name}/commit/${repository.last_sha}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className={`rounded-sm text-accent hover:text-accent-hover ${focusRing}`}
                                        >
                                            <code className="rounded-sm bg-surface-2 px-1 py-0.5 font-mono">{shortSha(repository.last_sha)}</code>
                                            <span className="sr-only"> {t('workspace.lastProcessedCommitLink')}</span>
                                        </a>
                                    ),
                                })}
                            </span>
                        )}
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2">
                        <a
                            href={repository.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={`inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-medium text-ink-muted ring-1 ring-line-strong ring-inset hover:bg-surface-2 hover:text-ink ${focusRing}`}
                        >
                            <GitHubIcon className="size-3.5" />
                            {t('workspace.gitHub')}
                            <span className="sr-only"> {t('common.opensInNewTab')}</span>
                        </a>

                        {canDelete && <DeleteRepositoryButton repository={repository} />}
                    </div>
                </div>

                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('workspace.closeContents')}
                    className={`inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`}
                >
                    <CloseIcon />
                </button>
            </div>

            <div className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <ChaptersNav groups={groups} />
            </div>
        </dialog>
    );
}
