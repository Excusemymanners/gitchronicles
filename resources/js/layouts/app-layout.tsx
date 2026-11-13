import { Link, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import type { MouseEvent, ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { buttonClasses } from '@/components/button';
import FlashMessage from '@/components/flash-message';
import LanguageSwitch from '@/components/language-switch';
import ThemeToggle from '@/components/theme-toggle';
import { useI18n } from '@/hooks/use-i18n';

type Props = {
    children: ReactNode;
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/** Lățimea și marginile laterale ale conținutului; antetul, paginile și subsolul se aliniază pe ea. */
export const pageContainer = 'mx-auto w-full max-w-6xl px-4 sm:px-6 lg:px-8';

const navLinkClasses = `rounded-md px-2 py-1.5 font-medium whitespace-nowrap text-ink-muted hover:text-ink aria-[current=page]:text-ink sm:px-2.5 ${focusRing}`;

/**
 * Rama paginilor din afara unui repository (prima pagină, autentificare, cont nou): antetul cu navigarea
 * (alta pentru vizitatori și pentru utilizatorii autentificați), comutatoarele de limbă și de temă, conținutul paginii,
 * subsolul și mesajul flash de la server.
 *
 * Antetul are aceeași înălțime ca bara spațiului de lucru (56 px) și rămâne sus la derulare.
 * Antetul și conținutul au nume de tranziție (view-transition-name): la trecerea dintre pagini antetul stă
 * pe loc, iar conținutul vechi se stinge și cel nou urcă în locul lui (animațiile sunt în resources/css/app.css).
 */
export default function AppLayout({ children }: Props) {
    const { props, url } = usePage();
    const { auth, registration_open } = props;
    const { t } = useI18n();

    // „Sari la conținut” mută focusul pe conținut fără să schimbe adresa paginii.
    const mainRef = useRef<HTMLElement>(null);

    function skipToContent(event: MouseEvent<HTMLAnchorElement>) {
        event.preventDefault();
        mainRef.current?.focus();
    }

    return (
        <div className="flex min-h-dvh flex-col bg-canvas text-ink">
            <a
                href="#continut"
                onClick={skipToContent}
                className="sr-only rounded-md bg-surface-raised px-3 py-2 text-sm font-medium text-accent focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:outline-2 focus:outline-accent"
            >
                {t('nav.skipToContent')}
            </a>

            <header
                style={{ viewTransitionName: 'site-header' }}
                className="sticky top-0 z-30 border-b border-line bg-canvas/85 backdrop-blur-md"
            >
                <div className={`flex h-14 items-center gap-1 sm:gap-2 ${pageContainer}`}>
                    <Link href="/" className={`mr-auto rounded-md text-ink ${focusRing}`}>
                        <AppLogo />
                        <span className="sr-only">{t('nav.homeSuffix')}</span>
                    </Link>

                    <nav aria-label={t('nav.main')} className="flex items-center gap-1 text-sm">
                        {/* Clasamentul „Descoperă” e public, așa că apare pentru toată lumea. */}
                        <Link href="/discover" aria-current={url.startsWith('/discover') ? 'page' : undefined} className={navLinkClasses}>
                            {t('nav.discover')}
                        </Link>
                        {auth.user ? (
                            <>
                                <span className="max-w-40 truncate px-2 text-ink-subtle max-sm:hidden">{auth.user.name}</span>
                                {auth.user.is_admin && <span className="rounded-sm bg-surface-2 px-1.5 py-0.5 text-xs font-medium text-ink-muted max-sm:hidden">{t('nav.adminTag')}</span>}
                                <Link href="/logout" method="post" as="button" className={`cursor-pointer ${navLinkClasses}`}>
                                    {t('nav.logOut')}
                                </Link>
                            </>
                        ) : (
                            <>
                                {/* Pe telefon bara e îngustă: „Autentificare” se scurtează la „Intră” (în engleză rămâne „Log in”). */}
                                <Link href="/login" aria-current={url.startsWith('/login') ? 'page' : undefined} className={navLinkClasses}>
                                    <span className="sm:hidden">{t('nav.logInShort')}</span>
                                    <span className="max-sm:hidden">{t('nav.logIn')}</span>
                                </Link>
                                {registration_open && (
                                    <>
                                        {/* Pe telefon rămâne doar „Autentificare”: pagina ei duce și la contul nou. */}
                                        <Link
                                            href="/register"
                                            aria-current={url.startsWith('/register') ? 'page' : undefined}
                                            className={buttonClasses({ variant: 'secondary', size: 'sm', className: 'ml-1 max-sm:hidden' })}
                                        >
                                            {t('nav.signUp')}
                                        </Link>
                                    </>
                                )}
                            </>
                        )}
                    </nav>

                    {/* Comutatoarele nu sunt legături de navigare, așa că stau lângă <nav>, nu în el. */}
                    <LanguageSwitch />
                    <ThemeToggle />
                </div>
            </header>

            <main
                id="continut"
                ref={mainRef}
                tabIndex={-1}
                style={{ viewTransitionName: 'page' }}
                className="flex flex-1 flex-col focus:outline-none"
            >
                {children}
            </main>

            <footer className="border-t border-line">
                <div className={`flex flex-col gap-1 py-6 text-[0.8125rem] text-ink-subtle sm:flex-row sm:items-center sm:justify-between ${pageContainer}`}>
                    <p>
                        <span className="font-brand font-semibold text-ink-muted">{t('common.appName')}</span> {t('footer.tagline')}
                    </p>
                    <p>{t('footer.thesis')}</p>
                </div>
            </footer>

            <FlashMessage />
        </div>
    );
}
