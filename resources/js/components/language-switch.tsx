import { router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import type { FocusEvent } from 'react';
import { useI18n } from '@/hooks/use-i18n';

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * Comutatorul de limbă din antet, lângă cel de temă: un buton cu limba de acum („EN”; pe telefon doar globul) care deschide lista
 * limbilor, fiecare scrisă în limba ei („English”, „Română”), cu cea de acum bifată (aria-current).
 *
 * Alegerea pleacă la server (POST /locale), care o ține minte într-un cookie și trimite înapoi aceeași pagină,
 * în limba nouă; Inertia o înlocuiește fără reîncărcare, cu aceeași trecere lină ca între pagini.
 * Lista se închide la Escape (focusul revine pe buton), la un clic în afara ei sau când focusul pleacă din ea.
 * Limbile vin de la server (prop-ul `locales`), așa că o limbă nouă apare aici fără schimbări în cod.
 */
export default function LanguageSwitch() {
    const { locale, t } = useI18n();
    const { locales } = usePage().props;
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const listId = useId();

    useEffect(() => {
        if (!isOpen) {
            return;
        }

        function closeOnOutsideClick(event: PointerEvent) {
            if (!containerRef.current?.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }

        function closeOnEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setIsOpen(false);
                buttonRef.current?.focus();
            }
        }

        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);

        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, [isOpen]);

    function closeWhenFocusLeaves(event: FocusEvent<HTMLDivElement>) {
        if (!containerRef.current?.contains(event.relatedTarget as Node | null)) {
            setIsOpen(false);
        }
    }

    function choose(code: string) {
        setIsOpen(false);

        if (code !== locale) {
            // preserveState păstrează ce ai scris deja într-un formular; preserveScroll, locul din pagină.
            router.post('/locale', { locale: code }, { preserveScroll: true, preserveState: true });
        }
    }

    return (
        <div ref={containerRef} onBlur={closeWhenFocusLeaves} className="relative">
            <button
                ref={buttonRef}
                type="button"
                onClick={() => setIsOpen(!isOpen)}
                aria-expanded={isOpen}
                aria-controls={listId}
                aria-label={t('language.button', { language: locales[locale] ?? locale })}
                title={t('language.change')}
                className={`pressable inline-flex h-9 shrink-0 cursor-pointer items-center gap-1 rounded-md px-2 text-sm font-medium text-ink-muted hover:bg-surface-2 hover:text-ink aria-expanded:bg-surface-2 aria-expanded:text-ink ${focusRing}`}
            >
                <GlobeIcon />
                {/* Pe telefon antetul e îngust: rămâne doar globul (numele limbii e în aria-label și în listă). */}
                <span className="max-sm:hidden">{locale.toUpperCase()}</span>
            </button>

            {isOpen && (
                <ul
                    id={listId}
                    className="absolute top-full right-0 z-40 mt-2 min-w-40 animate-fade-in rounded-lg border border-line bg-surface-raised p-1 shadow-float"
                >
                    {Object.entries(locales).map(([code, name]) => (
                        <li key={code}>
                            <button
                                type="button"
                                lang={code}
                                onClick={() => choose(code)}
                                aria-current={code === locale ? 'true' : undefined}
                                className={`flex w-full cursor-pointer items-center justify-between gap-4 rounded-md px-3 py-2 text-left text-sm text-ink hover:bg-surface-2 aria-[current]:font-medium ${focusRing}`}
                            >
                                {name}
                                {code === locale && <CheckIcon />}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const iconProps = {
    'aria-hidden': true,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
} as const;

function GlobeIcon() {
    return (
        <svg {...iconProps} className="size-5">
            <circle cx="12" cy="12" r="9" />
            <path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg {...iconProps} className="size-4 text-accent">
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </svg>
    );
}
