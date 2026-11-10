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
            className="block w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 shadow-xs placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-1 focus:outline-indigo-600 aria-[invalid=true]:border-red-500 aria-[invalid=true]:focus:outline-red-600"
        />
    );
}
