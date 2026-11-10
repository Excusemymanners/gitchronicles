import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

type FlashKind = 'error' | 'info' | 'success';

/** Ordinea importanței: dacă serverul trimite mai multe mesaje deodată, îl afișăm pe primul găsit. */
const flashKindsByPriority: FlashKind[] = ['error', 'info', 'success'];

/** Culoarea chenarului și a textului pentru fiecare tip de mesaj. */
const flashBoxClasses: Record<FlashKind, string> = {
    error: 'border-danger-line text-danger-ink',
    info: 'border-info-line text-info-ink',
    success: 'border-success-line text-success-ink',
};

/** Culoarea punctului din fața mesajului. */
const flashDotClasses: Record<FlashKind, string> = {
    error: 'bg-danger',
    info: 'bg-info',
    success: 'bg-success',
};

type VisibleFlash = {
    kind: FlashKind;
    text: string;
};

type Props = {
    /**
     * Unde apare mesajul: jos în dreapta (implicit) sau sus în dreapta, sub bara de sus,
     * în spațiul de lucru al unui repository, unde jos e bara asistentului.
     */
    placement?: 'bottom' | 'top';
};

/** Poziția zonei de mesaje pentru fiecare variantă. */
const placementClasses: Record<NonNullable<Props['placement']>, string> = {
    bottom: 'right-4 bottom-4 left-4 sm:right-6 sm:bottom-6 sm:left-auto',
    top: 'top-16 right-4 left-4 sm:right-6 sm:left-auto',
};

/**
 * Mesajul trimis de server cu Inertia::flash('success' | 'info' | 'error', '…') înainte de un redirect.
 * Fiecare răspuns nou de la server aduce un obiect `flash` nou. Când el conține un mesaj, îl păstrăm
 * în state și îl ascundem singuri după 5 secunde (sau la „×”). Un răspuns fără mesaj nu îl ascunde:
 * altfel reîmprospătarea automată a paginii (la 2 secunde, cât se procesează un repository)
 * l-ar șterge aproape imediat.
 */
export default function FlashMessage({ placement = 'bottom' }: Props) {
    const { flash } = usePage();
    const [visibleFlash, setVisibleFlash] = useState<VisibleFlash | null>(null);

    useEffect(() => {
        const kind = flashKindsByPriority.find((key) => flash[key]);
        const text = kind ? flash[kind] : undefined;

        if (kind && text) {
            setVisibleFlash({ kind, text });
        }
    }, [flash]);

    // Fiecare mesaj nou repornește numărătoarea de 5 secunde.
    useEffect(() => {
        if (!visibleFlash) {
            return;
        }

        const timer = window.setTimeout(() => setVisibleFlash(null), 5000);

        return () => window.clearTimeout(timer);
    }, [visibleFlash]);

    // Zona aria-live există mereu, ca cititoarele de ecran să anunțe mesajul când apare în ea.
    return (
        <div
            aria-live="polite"
            className={`pointer-events-none fixed z-50 flex justify-center ${placementClasses[placement]}`}
        >
            {visibleFlash && (
                <div
                    className={`pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-surface-raised p-4 text-sm shadow-lg ${flashBoxClasses[visibleFlash.kind]}`}
                >
                    <span aria-hidden="true" className={`mt-1.5 size-2 shrink-0 rounded-full ${flashDotClasses[visibleFlash.kind]}`} />
                    <p className="flex-1">{visibleFlash.text}</p>
                    <button
                        type="button"
                        onClick={() => setVisibleFlash(null)}
                        aria-label="Închide mesajul"
                        className={`-my-1 cursor-pointer rounded-md px-1.5 text-lg leading-none text-ink-subtle hover:text-ink ${focusRing}`}
                    >
                        ×
                    </button>
                </div>
            )}
        </div>
    );
}
