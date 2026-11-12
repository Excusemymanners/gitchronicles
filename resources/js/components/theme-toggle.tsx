import { useI18n } from '@/hooks/use-i18n';
import { withViewTransition } from '@/lib/motion';
import { setTheme, useTheme } from '@/lib/theme';

/**
 * Butonul din antet care schimbă tema. Iconița și eticheta arată tema în care treci:
 * în tema întunecată apare soarele („Switch to light theme”), în cea luminoasă luna („Switch to dark theme”).
 * Schimbarea se face cu o trecere lină a întregii pagini (withViewTransition), nu dintr-odată.
 */
export default function ThemeToggle() {
    const theme = useTheme();
    const { t } = useI18n();
    const nextTheme = theme === 'dark' ? 'light' : 'dark';
    const label = nextTheme === 'light' ? t('theme.toLight') : t('theme.toDark');

    return (
        <button
            type="button"
            onClick={() => withViewTransition(() => setTheme(nextTheme))}
            aria-label={label}
            title={label}
            className="pressable inline-flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-md text-ink-muted hover:bg-surface-2 hover:text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent"
        >
            {nextTheme === 'light' ? <SunIcon /> : <MoonIcon />}
        </button>
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
    className: 'size-5',
} as const;

function SunIcon() {
    return (
        <svg {...iconProps}>
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
        </svg>
    );
}

function MoonIcon() {
    return (
        <svg {...iconProps}>
            <path d="M20.5 14.1A8.5 8.5 0 0 1 9.9 3.5a8.5 8.5 0 1 0 10.6 10.6Z" />
        </svg>
    );
}
