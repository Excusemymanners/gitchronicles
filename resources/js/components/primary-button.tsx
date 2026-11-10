import type { ButtonHTMLAttributes } from 'react';

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'>;

/** Butonul principal al unui formular, în culoarea de accent. Cât timp e dezactivat, apare estompat. */
export default function PrimaryButton({ type = 'submit', ...props }: Props) {
    return (
        <button
            type={type}
            {...props}
            className="inline-flex cursor-pointer items-center justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-indigo-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        />
    );
}
