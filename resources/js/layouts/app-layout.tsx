import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';

type Props = {
    children: ReactNode;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600';

const navLinkClasses = `rounded-md px-2 py-1 font-medium text-gray-700 hover:text-indigo-600 ${focusRing}`;

/**
 * Rama comună a tuturor paginilor: antetul cu navigarea (alta pentru vizitatori și pentru
 * utilizatorii autentificați), conținutul paginii și mesajul flash de la server.
 */
export default function AppLayout({ children }: Props) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-gray-50 text-gray-900">
            <header className="border-b border-gray-200 bg-white">
                <div className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-3 sm:px-6">
                    <Link href="/" className={`rounded-md text-lg font-semibold tracking-tight text-gray-900 ${focusRing}`}>
                        GitChronicle
                    </Link>

                    <nav aria-label="Navigare principală" className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                        {auth.user ? (
                            <>
                                <span className="max-w-40 truncate px-2 text-gray-500">{auth.user.name}</span>
                                <Link href="/logout" method="post" as="button" className={`cursor-pointer ${navLinkClasses}`}>
                                    Ieșire
                                </Link>
                            </>
                        ) : (
                            <>
                                <Link href="/login" className={navLinkClasses}>
                                    Autentificare
                                </Link>
                                <Link
                                    href="/register"
                                    className={`rounded-md bg-indigo-600 px-3 py-1.5 font-semibold text-white shadow-xs hover:bg-indigo-500 ${focusRing}`}
                                >
                                    Cont nou
                                </Link>
                            </>
                        )}
                    </nav>
                </div>
            </header>

            <main className="mx-auto max-w-4xl px-4 py-8 sm:px-6 sm:py-10">{children}</main>

            <FlashMessage />
        </div>
    );
}

type FlashKind = 'error' | 'info' | 'success';

/** Ordinea importanței: dacă serverul trimite mai multe mesaje deodată, îl afișăm pe primul găsit. */
const flashKindsByPriority: FlashKind[] = ['error', 'info', 'success'];

/** Culoarea chenarului și a textului pentru fiecare tip de mesaj. */
const flashBoxClasses: Record<FlashKind, string> = {
    error: 'border-red-200 text-red-800',
    info: 'border-indigo-200 text-indigo-800',
    success: 'border-green-200 text-green-800',
};

/** Culoarea punctului din fața mesajului. */
const flashDotClasses: Record<FlashKind, string> = {
    error: 'bg-red-500',
    info: 'bg-indigo-500',
    success: 'bg-green-500',
};

/**
 * Mesajul trimis de server cu Inertia::flash('success' | 'info' | 'error', '…') înainte de un redirect.
 * Fiecare răspuns nou de la server aduce un obiect `flash` nou, deci efectul rulează o dată pentru
 * fiecare răspuns: afișează mesajul și îl ascunde singur după 5 secunde. Se poate închide și cu „×”.
 */
function FlashMessage() {
    const { flash } = usePage();
    const [isVisible, setIsVisible] = useState(false);

    const kind = flashKindsByPriority.find((key) => flash[key]);

    useEffect(() => {
        // Un răspuns fără mesaj (de exemplu o eroare de validare) ascunde mesajul rămas de dinainte.
        if (!kind) {
            setIsVisible(false);
            return;
        }

        setIsVisible(true);
        const timer = window.setTimeout(() => setIsVisible(false), 5000);

        return () => window.clearTimeout(timer);
    }, [flash]);

    // Zona aria-live există mereu, ca cititoarele de ecran să anunțe mesajul când apare în ea.
    return (
        <div
            aria-live="polite"
            className="pointer-events-none fixed right-4 bottom-4 left-4 z-50 flex justify-center sm:right-6 sm:bottom-6 sm:left-auto"
        >
            {isVisible && kind && (
                <div
                    className={`pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-white p-4 text-sm shadow-lg ${flashBoxClasses[kind]}`}
                >
                    <span aria-hidden="true" className={`mt-1.5 size-2 shrink-0 rounded-full ${flashDotClasses[kind]}`} />
                    <p className="flex-1">{flash[kind]}</p>
                    <button
                        type="button"
                        onClick={() => setIsVisible(false)}
                        aria-label="Închide mesajul"
                        className={`-my-1 cursor-pointer rounded-md px-1.5 text-lg leading-none text-gray-400 hover:text-gray-600 ${focusRing}`}
                    >
                        ×
                    </button>
                </div>
            )}
        </div>
    );
}
