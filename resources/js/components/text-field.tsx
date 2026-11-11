import type { InputHTMLAttributes } from 'react';
import TextInput from '@/components/text-input';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className' | 'size'> & {
    /** Eticheta afișată deasupra câmpului. */
    label: string;
    name: string;
    /** Un indiciu scurt sub câmp (de exemplu regula parolei). */
    hint?: string;
    /** Mesajul de validare primit de la server pentru acest câmp. */
    error?: string;
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
            {error && (
                <p id={errorId} className="animate-fade-in text-sm text-danger">
                    {error}
                </p>
            )}
            {hint && (
                <p id={hintId} className="text-[0.8125rem] text-ink-subtle">
                    {hint}
                </p>
            )}
        </div>
    );
}
