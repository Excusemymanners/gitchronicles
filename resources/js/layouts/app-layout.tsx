import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import FlashMessage from '@/components/flash-message';
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
