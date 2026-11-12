import type { ReactNode } from 'react';

/*
 * Icons used only by the documentation chapter. They follow the shared set in components/icons.tsx
 * (1.75 stroke on a 24 grid, currentColor, decorative) but live here because the shared file is frozen.
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

export function LinkIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M10 14a4 4 0 0 0 5.66 0l3-3a4 4 0 0 0-5.66-5.66l-1 1M14 10a4 4 0 0 0-5.66 0l-3 3a4 4 0 0 0 5.66 5.66l1-1" />
        </Icon>
    );
}

export function CopyIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <rect x="9" y="9" width="11" height="11" rx="2" />
            <path d="M5 15V6a2 2 0 0 1 2-2h9" />
        </Icon>
    );
}

export function CheckIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </Icon>
    );
}

export function ClockIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="8.5" />
            <path d="M12 7.5V12l3 2" />
        </Icon>
    );
}

export function ChevronLeftIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m14.5 6-6 6 6 6" />
        </Icon>
    );
}

export function ChevronRightIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m9.5 6 6 6-6 6" />
        </Icon>
    );
}
