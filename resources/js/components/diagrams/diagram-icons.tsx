import type { ReactNode } from 'react';

/*
 * Icons of the diagram toolbar (the shared icons file is frozen, so these live here).
 * Same style as components/icons.tsx: 1.75 strokes on a 24 grid, currentColor, decorative.
 */

type IconProps = { className?: string };

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

export function ZoomInIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="11" cy="11" r="6.5" />
            <path d="m20 20-4.2-4.2M11 8.5v5M8.5 11h5" />
        </Icon>
    );
}

export function ZoomOutIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="11" cy="11" r="6.5" />
            <path d="m20 20-4.2-4.2M8.5 11h5" />
        </Icon>
    );
}

export function FitIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 9V5a1 1 0 0 1 1-1h4M15 4h4a1 1 0 0 1 1 1v4M20 15v4a1 1 0 0 1-1 1h-4M9 20H5a1 1 0 0 1-1-1v-4" />
            <rect x="9" y="9" width="6" height="6" rx="1" />
        </Icon>
    );
}

export function FullScreenIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5" />
        </Icon>
    );
}

export function CopyCodeIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m9 8-4 4 4 4M15 8l4 4-4 4" />
        </Icon>
    );
}

export function DownloadIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 4v11M7.5 10.5 12 15l4.5-4.5M5 20h14" />
        </Icon>
    );
}

export function ImageIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <rect x="4" y="5" width="16" height="14" rx="2" />
            <circle cx="9" cy="10" r="1.5" />
            <path d="m4.5 17 4.5-4.5 3 3 3-3 4.5 4.5" />
        </Icon>
    );
}
