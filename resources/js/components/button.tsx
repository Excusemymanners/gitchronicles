import type { ButtonHTMLAttributes } from 'react';

/*
 * The one button system of the app (Brand 3). Four kinds, three sizes, the same shape everywhere:
 * - primary: the one thing to do on a screen. Neutral, not coloured: a light button on the dark page, an
 *   anthracite one on the light page (accent-strong), so the blue accent stays for links, focus and selection;
 * - secondary: everything else that is a button (surface, a fine line that strengthens on hover);
 * - ghost: a quiet action inside a toolbar or a list (no edge until hover);
 * - danger: an action that deletes or cannot be undone (danger text; its edge and ground warm up on hover).
 * Every button: 8 px corners, a 2 px accent focus ring with an offset, a short press (pressable), icons at the
 * size of the text and centred on the capitals (fixed height + leading-none), and a loading state that keeps the
 * label so the button does not change width.
 *
 * A link that looks like a button (an Inertia <Link>, an <a>) takes the same classes from buttonClasses().
 */

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type ButtonSize = 'sm' | 'md' | 'lg';

const baseClasses =
    'pressable inline-flex cursor-pointer items-center justify-center rounded-md leading-none whitespace-nowrap select-none ' +
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ' +
    'disabled:cursor-not-allowed disabled:opacity-55 aria-disabled:cursor-not-allowed aria-disabled:opacity-55 ' +
    '[&_svg]:shrink-0';

const variantClasses: Record<ButtonVariant, string> = {
    primary: 'bg-accent-strong font-semibold text-on-accent shadow-card hover:bg-accent-strong-hover',
    secondary: 'bg-surface font-medium text-ink ring-1 ring-line ring-inset hover:bg-surface-2 hover:ring-line-strong',
    ghost: 'font-medium text-ink-muted hover:bg-surface-2 hover:text-ink',
    danger: 'bg-surface font-medium text-danger ring-1 ring-line ring-inset hover:bg-danger-soft hover:ring-danger-line',
};

/** Height, padding, text size and the gap to an icon; the icon is as large as the text's capitals plus a little. */
const sizeClasses: Record<ButtonSize, string> = {
    sm: 'h-8 gap-1.5 px-3 text-[0.8125rem] [&_svg]:size-3.5',
    md: 'h-9 gap-2 px-4 text-sm [&_svg]:size-4',
    lg: 'h-11 gap-2 px-5 text-base [&_svg]:size-[1.125rem]',
};

type ClassOptions = {
    variant?: ButtonVariant;
    size?: ButtonSize;
    className?: string;
};

/** The classes of a button of the given kind and size, for a <Link> or an <a> that should look like one. */
export function buttonClasses({ variant = 'secondary', size = 'md', className = '' }: ClassOptions = {}): string {
    return `${baseClasses} ${variantClasses[variant]} ${sizeClasses[size]} ${className}`.trim();
}

/** The small turning circle of a button that is working. It stops (as a still arc) under reduced motion. */
export function Spinner({ className = '' }: { className?: string }) {
    return (
        <svg aria-hidden="true" viewBox="0 0 16 16" fill="none" className={`animate-spin ${className}`}>
            <circle cx="8" cy="8" r="6.25" stroke="currentColor" strokeOpacity="0.25" strokeWidth="1.75" />
            <path d="M14.25 8A6.25 6.25 0 0 0 8 1.75" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" />
        </svg>
    );
}

type Props = ButtonHTMLAttributes<HTMLButtonElement> & {
    variant?: ButtonVariant;
    size?: ButtonSize;
    /**
     * The action is running: the button shows a spinner before its label, cannot be pressed again and tells
     * screen readers it is busy. The caller may also change the label ("Adding…").
     */
    loading?: boolean;
};

/** A <button> of the system. `type` is "button" unless said otherwise, so it never submits a form by accident. */
export default function Button({ variant = 'secondary', size = 'md', loading = false, type = 'button', className = '', disabled, children, ...props }: Props) {
    return (
        <button
            type={type}
            disabled={disabled || loading}
            aria-busy={loading || undefined}
            {...props}
            className={buttonClasses({ variant, size, className })}
        >
            {loading && <Spinner />}
            {children}
        </button>
    );
}
