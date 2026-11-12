import { router } from '@inertiajs/react';
import { useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { useI18n } from '@/hooks/use-i18n';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** Classes of the button that opens the confirmation (it sits in the top bar's overflow menu). */
    className: string;
    /** Called right before the confirmation opens, so the menu around the button can close. */
    onOpen?: () => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * "Delete" for a repository: a designed confirmation in a <dialog> that names the repository and what is lost,
 * then DELETE /repositories/{id}. The server deletes it and redirects to the home page.
 *
 * The dialog is rendered into <body> (a portal) because the button lives in a popover menu that hides itself
 * when the dialog opens; showModal() gives focus trapping, Esc and the backdrop for free.
 */
export default function DeleteRepositoryButton({ repository, className, onOpen }: Props) {
    const { t } = useI18n();
    const dialogRef = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const textId = useId();
    const [isDeleting, setIsDeleting] = useState(false);

    function open() {
        onOpen?.();
        dialogRef.current?.showModal();
    }

    function confirmDelete() {
        router.delete(`/repositories/${repository.id}`, {
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
        });
    }

    return (
        <>
            <button type="button" onClick={open} className={className}>
                {t('repository.delete.label')}
                <span className="sr-only"> {repository.full_name}</span>
            </button>

            {createPortal(
                <dialog
                    ref={dialogRef}
                    aria-labelledby={titleId}
                    aria-describedby={textId}
                    className="m-auto w-[min(28rem,calc(100vw-2rem))] rounded-xl border border-line bg-surface-raised p-6 text-ink shadow-float backdrop:bg-canvas/80 backdrop:backdrop-blur-sm open:motion-safe:animate-rise"
                >
                    <h2 id={titleId} className="font-display text-lg font-semibold">
                        {t('repository.delete.title')}
                    </h2>
                    <p id={textId} className="mt-2 text-sm leading-relaxed text-ink-muted">
                        {t('repository.delete.text', { repository: repository.full_name })}
                    </p>
                    <div className="mt-6 flex flex-wrap justify-end gap-2">
                        <button
                            type="button"
                            autoFocus
                            onClick={() => dialogRef.current?.close()}
                            className={`pressable inline-flex cursor-pointer items-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-2 ${focusRing}`}
                        >
                            {t('repository.delete.cancel')}
                        </button>
                        <button
                            type="button"
                            onClick={confirmDelete}
                            disabled={isDeleting}
                            className="pressable inline-flex cursor-pointer items-center rounded-md bg-danger px-3 py-1.5 text-sm font-semibold text-on-accent hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {isDeleting ? t('repository.delete.deleting') : t('repository.delete.confirmButton')}
                        </button>
                    </div>
                </dialog>,
                document.body,
            )}
        </>
    );
}
