import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import ThemeToggle from '@/components/theme-toggle';

type Props = {
    children: ReactNode;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

const navLinkClasses = `rounded-md px-2 py-1 font-medium text-ink-muted hover:text-accent ${focusRing}`;

/**
 * Rama comună a tuturor paginilor: antetul cu navigarea (alta pentru vizitatori și pentru
 * utilizatorii autentificați) și comutatorul de temă, conținutul paginii și mesajul flash de la server.
 */
export default function AppLayout({ children }: Props) {
    const { auth } = usePage().props;

    return (
        <div className="min-h-screen bg-canvas text-ink">
            <header className="border-b border-line bg-surface">
                {/*
                  Pe ecrane late: logo, navigarea, apoi comutatorul de temă. Pe telefon navigarea trece singură
                  pe al doilea rând (order-last, toată lățimea), iar comutatorul rămâne sus, lângă logo.
                */}
                <div className="mx-auto flex max-w-4xl flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-6">
                    <Link href="/" className={`mr-auto rounded-md text-ink ${focusRing}`}>
                        <AppLogo />
                    </Link>

                    <nav
                        aria-label="Navigare principală"
                        className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm max-sm:order-last max-sm:-ml-2 max-sm:w-full"
                    >
                        {auth.user ? (
                            <>
                                <span className="max-w-40 truncate px-2 text-ink-subtle">{auth.user.name}</span>
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
                                    className={`rounded-md bg-accent-strong px-3 py-1.5 font-semibold text-on-accent shadow-xs hover:bg-accent-strong-hover ${focusRing}`}
                                >
                                    Cont nou
                                </Link>
                            </>
                        )}
                    </nav>

                    {/* Comutatorul nu e o legătură de navigare, așa că stă lângă <nav>, nu în el. */}
                    <ThemeToggle />
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

/**
 * Mesajul trimis de server cu Inertia::flash('success' | 'info' | 'error', '…') înainte de un redirect.
 * Fiecare răspuns nou de la server aduce un obiect `flash` nou. Când el conține un mesaj, îl păstrăm
 * în state și îl ascundem singuri după 5 secunde (sau la „×”). Un răspuns fără mesaj nu îl ascunde:
 * altfel reîmprospătarea automată a paginii (la 2 secunde, cât se procesează un repository)
 * l-ar șterge aproape imediat.
 */
function FlashMessage() {
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
            className="pointer-events-none fixed right-4 bottom-4 left-4 z-50 flex justify-center sm:right-6 sm:bottom-6 sm:left-auto"
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
