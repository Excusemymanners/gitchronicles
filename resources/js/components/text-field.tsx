import type { InputHTMLAttributes } from 'react';
import TextInput from '@/components/text-input';

type Props = Omit<InputHTMLAttributes<HTMLInputElement>, 'className'> & {
    /** Eticheta afișată deasupra câmpului. */
    label: string;
    name: string;
    /** Mesajul de validare primit de la server pentru acest câmp. */
    error?: string;
};

/**
 * Un câmp de formular complet: eticheta legată de input (htmlFor = id), inputul și eroarea de sub el.
 * `id`-ul este implicit același cu `name`. Eroarea e legată de input prin aria-describedby,
 * ca cititoarele de ecran s-o anunțe împreună cu câmpul.
 */
export default function TextField({ label, name, error, id = name, ...inputProps }: Props) {
    const errorId = `${id}-error`;

    return (
        <div className="flex flex-col gap-1.5">
            <label htmlFor={id} className="text-sm font-medium text-gray-700">
                {label}
            </label>
            <TextInput
                id={id}
                name={name}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? errorId : undefined}
                {...inputProps}
            />
            {error && (
                <p id={errorId} className="text-sm text-red-600">
                    {error}
                </p>
            )}
        </div>
    );
}
