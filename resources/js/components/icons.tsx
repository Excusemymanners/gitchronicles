import type { ReactNode } from 'react';

/*
 * Iconițele comune ale aplicației: linii de 1,75 pe o grilă de 24, în culoarea textului din jur (currentColor).
 * Sunt decorative (aria-hidden): sensul îl dă mereu textul de lângă ele sau eticheta butonului.
 * Mărimea vine din className (size-4 lângă text mic, size-5 într-un buton cu iconiță).
 */

type IconProps = {
    className?: string;
};

function Icon({ className = 'size-4', children }: IconProps & { children: ReactNode }) {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={1.75}
            strokeLinecap="round"
            strokeLinejoin="round"
            className={`shrink-0 ${className}`}
        >
            {children}
        </svg>
    );
}

export function ArrowRightIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </Icon>
    );
}

export function CloseIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M6 6l12 12M18 6L6 18" />
        </Icon>
    );
}

/** Documentația: o pagină cu rânduri. */
export function DocumentIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M7 3h7l4 4v13a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" />
            <path d="M14 3v4h4M9 12h6M9 16h6" />
        </Icon>
    );
}

/** Diagramele: trei cutii legate între ele. */
export function DiagramIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <rect x="9" y="3" width="6" height="5" rx="1" />
            <rect x="3" y="16" width="6" height="5" rx="1" />
            <rect x="15" y="16" width="6" height="5" rx="1" />
            <path d="M12 8v4M6 16v-2a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v2" />
        </Icon>
    );
}

/** Asistentul: un balon de dialog. */
export function AssistantIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M20 12a8 8 0 0 1-11.6 7.1L4 20l1-4.2A8 8 0 1 1 20 12Z" />
            <path d="M9 11h6M9 14h3" />
        </Icon>
    );
}

/** Istoricul: un cadran de ceas, ca în logo. */
export function HistoryIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 7v5l3 2" />
        </Icon>
    );
}

/** Video-ul: un ecran vertical cu semnul de redare. */
export function VideoIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <rect x="6" y="3" width="12" height="18" rx="2" />
            <path d="M10.5 9.5v5l4-2.5-4-2.5Z" />
        </Icon>
    );
}

/** O legătură care se deschide într-o filă nouă: o săgeată care iese din colțul unei cutii. */
export function ExternalLinkIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" />
        </Icon>
    );
}
