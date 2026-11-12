/*
 * Iconițele spațiului de lucru, desenate cu linii (stroke), ca butonul de temă. Sunt decorative:
 * numele butonului îl dă aria-label sau textul de lângă iconiță.
 */

type Props = {
    className?: string;
};

const strokeProps = {
    'aria-hidden': true,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
} as const;

/** Trei linii: deschide cuprinsul pe telefon. */
export function MenuIcon({ className = 'size-5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    );
}

/** Un „×”: închide un panou. */
export function CloseIcon({ className = 'size-5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M6 6l12 12M18 6L6 18" />
        </svg>
    );
}

/** O săgeată spre dreapta, care se rotește în jos când folderul e deschis. */
export function ChevronIcon({ className = 'size-3.5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M9 6l6 6-6 6" />
        </svg>
    );
}

/** O săgeată în sus: trimite întrebarea. */
export function ArrowUpIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} strokeWidth={2} className={className}>
            <path d="M12 19V5M5 12l7-7 7 7" />
        </svg>
    );
}

/** O săgeată spre dreapta: deschide capitolul unui card. */
export function ArrowRightIcon({ className = 'size-3.5' }: Props) {
    return (
        <svg {...strokeProps} strokeWidth={2} className={className}>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </svg>
    );
}

/** Lupa din câmpul de filtrare a modulelor. */
export function SearchIcon({ className = 'size-3.5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <circle cx="11" cy="11" r="7" />
            <path d="M20 20l-3.5-3.5" />
        </svg>
    );
}

/** Semnul GitHub (Octicons, licență MIT), plin, ca pe github.com. */
export function GitHubIcon({ className = 'size-4' }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 16 16" fill="currentColor" className={className}>
            <path d="M8 0c4.42 0 8 3.58 8 8a8.013 8.013 0 0 1-5.45 7.59c-.4.08-.55-.17-.55-.38 0-.27.01-1.13.01-2.2 0-.75-.25-1.23-.54-1.48 1.78-.2 3.65-.88 3.65-3.95 0-.88-.31-1.59-.82-2.15.08-.2.36-1.02-.08-2.12 0 0-.67-.22-2.2.82-.64-.18-1.32-.27-2-.27-.68 0-1.36.09-2 .27-1.53-1.03-2.2-.82-2.2-.82-.44 1.1-.16 1.92-.08 2.12-.51.56-.82 1.28-.82 2.15 0 3.06 1.86 3.75 3.64 3.95-.23.2-.44.55-.51 1.07-.46.21-1.61.55-2.33-.66-.15-.24-.6-.83-1.23-.82-.67.01-.27.38.01.53.34.19.73.9.82 1.13.16.45.68 1.31 2.69.94 0 .67.01 1.3.01 1.49 0 .21-.15.45-.55.38A7.995 7.995 0 0 1 0 8c0-4.42 3.58-8 8-8Z" />
        </svg>
    );
}

/** Three dots: opens the overflow menu of the top bar. */
export function MoreIcon({ className = 'size-5' }: Props) {
    return (
        <svg {...strokeProps} strokeWidth={2.25} className={className}>
            <path d="M5 12h.01M12 12h.01M19 12h.01" />
        </svg>
    );
}

/** Two arrows in a circle: "Check for updates"; it spins while the check runs. */
export function RefreshIcon({ className = 'size-3.5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M20 11a8 8 0 0 0-14.9-3.5M4 4v4h4M4 13a8 8 0 0 0 14.9 3.5M20 20v-4h-4" />
        </svg>
    );
}

/** A filled triangle: plays the video. */
export function PlayIcon({ className = 'size-6' }: Props) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="currentColor" className={className}>
            <path d="M8 5.14v13.72a1 1 0 0 0 1.52.85l10.6-6.86a1 1 0 0 0 0-1.7L9.52 4.29A1 1 0 0 0 8 5.14Z" />
        </svg>
    );
}

/** An arrow into a tray: downloads a file. */
export function DownloadIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M12 4v11M7 10l5 5 5-5M5 20h14" />
        </svg>
    );
}

/** Two sheets: copies a text to the clipboard. */
export function CopyIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <rect x="9" y="9" width="11" height="11" rx="2" />
            <path d="M5 15V6a2 2 0 0 1 2-2h9" />
        </svg>
    );
}

/** A tick: the copy worked. */
export function CheckIcon({ className = 'size-4' }: Props) {
    return (
        <svg {...strokeProps} strokeWidth={2} className={className}>
            <path d="M5 12.5l4.5 4.5L19 7.5" />
        </svg>
    );
}

/** A speech bubble: the floating "Ask" button and the ask box. */
export function AskIcon({ className = 'size-5' }: Props) {
    return (
        <svg {...strokeProps} className={className}>
            <path d="M5 18.5V6a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H8.5L5 18.5Z" />
            <path d="M9 9h6M9 12h3.5" />
        </svg>
    );
}
