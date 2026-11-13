import type { InputHTMLAttributes } from 'react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className' | 'size'> & {
    /** "lg" is the field of a large form (the home page, log in, sign up); "md" the usual one. Heights match the buttons. */
    size?: 'md' | 'lg';
};

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    md: 'h-9 px-3 text-sm',
    lg: 'h-11 px-3.5 text-base',
};

/**
 * The look of every text field (Brand 3): the surface, an edge that holds 3:1 against the page (line-strong) and
 * strengthens on hover, and on focus a 2 px accent ring drawn over the edge. With aria-invalid="true" the edge
 * and the ring turn to the danger colour.
 *
 * fieldShellClasses is the same look for a "shell": a frame that holds a bare <input> and a button side by side
 * (the home page's link field, the Overview's ask box). The ring follows the input inside it.
 */
export const fieldClasses =
    'block w-full rounded-md border border-line-strong bg-surface text-ink shadow-card placeholder:text-ink-subtle ' +
    'hover:border-ink-subtle focus:border-accent focus:outline-2 focus:-outline-offset-1 focus:outline-accent ' +
    'aria-[invalid=true]:border-danger aria-[invalid=true]:focus:outline-danger';

export function fieldShellClasses(hasError = false): string {
    const state = hasError
        ? 'border-danger has-[input:focus-visible]:outline-danger'
        : 'border-line-strong hover:border-ink-subtle has-[input:focus-visible]:border-accent has-[input:focus-visible]:outline-accent';

    return `border bg-surface shadow-card has-[input:focus-visible]:outline-2 has-[input:focus-visible]:-outline-offset-1 ${state}`;
}

/** An <input> in the app's style. Every attribute (name, type, autoComplete…) goes straight onto the input. */
export default function TextInput({ size = 'md', ...props }: Props) {
    return <input {...props} className={`${fieldClasses} ${sizeClasses[size]}`} />;
}
