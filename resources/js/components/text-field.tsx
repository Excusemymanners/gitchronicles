import type { InputHTMLAttributes } from 'react';
import { AlertIcon } from '@/components/icons';
import TextInput from '@/components/text-input';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className' | 'size'> & {
    /** Eticheta afișată deasupra câmpului. */
    label: string;
    name: string;
    /** Un indiciu scurt sub câmp (de exemplu regula parolei). */
    hint?: string;
    /** Mesajul de validare primit de la server pentru acest câmp. */
    error?: string;
    /** "lg" for a large form (log in, sign up); the default "md" elsewhere. */
    size?: 'md' | 'lg';
};

/**
 * Un câmp de formular complet: eticheta legată de input (htmlFor = id), inputul, indiciul și eroarea de sub el.
 * `id`-ul este implicit același cu `name`. Indiciul și eroarea sunt legate de input prin aria-describedby,
 * ca cititoarele de ecran să le anunțe împreună cu câmpul.
 */
export default function TextField({ label, name, hint, error, id = name, ...inputProps }: Props) {
    const errorId = `${id}-error`;
    const hintId = `${id}-hint`;
    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ') || undefined;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium text-ink">
                {label}
            </label>
            <TextInput id={id} name={name} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...inputProps} />
            {error && <FieldError id={errorId}>{error}</FieldError>}
            {hint && (
                <p id={hintId} className="text-small text-ink-subtle">
                    {hint}
                </p>
            )}
        </div>
    );
}

/**
 * A field's validation message: the danger colour and a small alert sign, so the error does not rely on colour
 * alone. It fades in, so a message that appears after sending is noticed.
 */
export function FieldError({ id, children, className = '' }: { id: string; children: string; className?: string }) {
    return (
        <p id={id} className={`flex animate-fade-in items-start gap-1.5 text-sm text-danger ${className}`}>
            <AlertIcon className="mt-[0.2em] size-[1em]" />
            {children}
        </p>
    );
}
