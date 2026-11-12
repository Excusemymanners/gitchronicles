import { useEffect, useRef } from 'react';
import type { MouseEvent } from 'react';
import DocsNav from '@/components/workspace/docs-nav';
import { CloseIcon } from '@/components/workspace/icons';
import { useI18n } from '@/hooks/use-i18n';
import type { WorkspaceGroup } from '@/types';

type Props = {
    groups: WorkspaceGroup[];
    isOpen: boolean;
    onClose: () => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * The documentation's pages on a narrow screen: a drawer that slides in from the left over the article. It is a
 * <dialog> opened with showModal(), so the browser keeps the focus inside, closes it on Esc and makes the page
 * behind it inert. A click on the backdrop or on a page link closes it too.
 */
export default function DocsNavDrawer({ groups, isOpen, onClose }: Props) {
    const { t } = useI18n();
    const dialogRef = useRef<HTMLDialogElement>(null);

    // On opening, the focus goes to the open page (scrolled into view), so Tab continues through the list.
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

    // A click on the <dialog> itself (not on its content) is a click on the backdrop around the drawer.
    function handleClick(event: MouseEvent<HTMLDialogElement>) {
        const target = event.target as HTMLElement;

        if (target === dialogRef.current || target.closest('a') !== null) {
            onClose();
        }
    }

    return (
        <dialog
            ref={dialogRef}
            aria-label={t('workspace.docsNav.label')}
            onClose={onClose}
            onClick={handleClick}
            className="fixed inset-y-0 left-0 m-0 h-dvh max-h-dvh w-[min(20rem,85vw)] max-w-none overflow-hidden rounded-r-xl border-r border-line bg-surface-raised p-0 text-ink shadow-float backdrop:bg-canvas/80 backdrop:backdrop-blur-sm open:flex open:flex-col motion-safe:transition-transform motion-safe:duration-(--motion-slow) motion-safe:ease-out starting:open:-translate-x-full"
        >
            <div className="flex shrink-0 items-center justify-between gap-3 border-b border-line py-2 pr-2 pl-5">
                <p className="text-sm font-semibold text-ink">{t('workspace.docsNav.label')}</p>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={t('workspace.docsNav.close')}
                    className={`inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`}
                >
                    <CloseIcon />
                </button>
            </div>

            <div className="relative min-h-0 flex-1 overflow-y-auto overscroll-contain">
                <DocsNav groups={groups} />
            </div>
        </dialog>
    );
}
