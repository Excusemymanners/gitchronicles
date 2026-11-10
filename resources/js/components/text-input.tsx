import type { InputHTMLAttributes } from 'react';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className'>;

/**
 * Un <input> cu stilul aplicației. Orice atribut (name, type, autoComplete…) ajunge direct pe input.
 * Când are aria-invalid="true" (câmp cu eroare), marginea și conturul de focus devin roșii.
 */
export default function TextInput(props: Props) {
    return (
        <input
            {...props}
            className="block w-full rounded-md border border-line-strong bg-surface px-3 py-2 text-sm text-ink shadow-xs placeholder:text-ink-subtle focus:outline-2 focus:-outline-offset-1 focus:outline-accent aria-[invalid=true]:border-danger aria-[invalid=true]:focus:outline-danger"
        />
    );
}
