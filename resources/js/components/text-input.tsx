import type { InputHTMLAttributes } from 'react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className' | 'size'> & {
    /** „lg” e câmpul formularului mare de pe prima pagină; „md” e cel obișnuit. */
    size?: 'md' | 'lg';
};

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    md: 'px-3 py-2 text-sm',
    lg: 'px-4 py-3 text-base',
};

/**
 * Un <input> cu stilul aplicației. Orice atribut (name, type, autoComplete…) ajunge direct pe input.
 * La hover marginea se întărește puțin; cu aria-invalid="true" (câmp cu eroare), marginea și conturul
 * de focus devin roșii.
 */
export default function TextInput({ size = 'md', ...props }: Props) {
    return (
        <input
            {...props}
            className={`block w-full rounded-md border border-line-strong bg-surface text-ink shadow-card placeholder:text-ink-subtle hover:border-ink-subtle focus:outline-2 focus:-outline-offset-1 focus:outline-accent aria-[invalid=true]:border-danger aria-[invalid=true]:focus:outline-danger ${sizeClasses[size]}`}
        />
    );
}
