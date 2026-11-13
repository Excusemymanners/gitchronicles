import { useEffect, useRef } from 'react';
import { CloseIcon } from '@/components/icons';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    url: string;
    alt: string;
    caption: string;
    onClose: () => void;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * A picture at full size in a modal dialog, in the style of the diagrams' full-screen view: Escape or the close
 * button closes it, the backdrop is blurred. The picture comes from this app, never from another site.
 */
export default function PictureDialog({ url, alt, caption, onClose }: Props) {
    const { t } = useI18n();
    const dialogRef = useRef<HTMLDialogElement>(null);

    useEffect(() => {
        dialogRef.current?.showModal();
    }, []);

    return (
        <dialog
            ref={dialogRef}
            aria-label={alt || caption}
            onClose={onClose}
            onClick={(event) => event.target === dialogRef.current && dialogRef.current?.close()}
            className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100vw-2rem)] max-w-5xl overflow-auto rounded-lg border border-line bg-surface-raised p-0 text-ink shadow-float backdrop:bg-canvas/80 backdrop:backdrop-blur-sm"
        >
            <div className="flex items-start justify-between gap-4 border-b border-line px-4 py-3">
                <p className="text-sm text-ink-muted">{caption}</p>
                <button
                    type="button"
                    onClick={() => dialogRef.current?.close()}
                    aria-label={t('docs.visuals.close')}
                    className={`pressable -m-1 cursor-pointer rounded-md p-1 text-ink-muted hover:bg-surface-2 hover:text-ink ${focusRing}`}
                >
                    <CloseIcon className="size-5" />
                </button>
            </div>
            <div className="bg-surface p-4">
                <img src={url} alt={alt} className="mx-auto max-h-[calc(100dvh-8rem)] w-auto max-w-full object-contain" />
            </div>
        </dialog>
    );
}
