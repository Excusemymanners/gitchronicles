import type { ReactNode } from 'react';

/*
 * Icons used only by the assistant. They follow the shared set in components/icons.tsx
 * (1.75 stroke on a 24 grid, currentColor, decorative) but live here because the shared file is frozen.
 */

function Icon({ className = 'size-4', children }: { className?: string; children: ReactNode }) {
    return (
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" className={`shrink-0 ${className}`}>
            {children}
        </svg>
    );
}

export function LinkIcon({ className }: { className?: string }) {
    return (
        <Icon className={className}>
            <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" />
        </Icon>
    );
}

export function CheckIcon({ className }: { className?: string }) {
    return (
        <Icon className={className}>
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </Icon>
    );
}

export function ArrowUpRightIcon({ className }: { className?: string }) {
    return (
        <Icon className={className}>
            <path d="M5 12h14M13 6l6 6-6 6" />
        </Icon>
    );
}
