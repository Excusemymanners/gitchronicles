import type { ButtonHTMLAttributes } from 'react';

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> & {
    /** „lg” e butonul formularului mare de pe prima pagină; „md” e cel obișnuit. */
    size?: 'md' | 'lg';
};

const sizeClasses: Record<NonNullable<Props['size']>, string> = {
    md: 'px-4 py-2 text-sm',
    lg: 'px-5 py-3 text-base',
};

/**
 * Butonul principal al unui formular, în culoarea de accent. Se strânge puțin cât e apăsat (pressable);
 * cât timp e dezactivat, apare estompat.
 */
export default function PrimaryButton({ type = 'submit', size = 'md', ...props }: Props) {
    return (
        <button
            type={type}
            {...props}
            className={`pressable inline-flex cursor-pointer items-center justify-center gap-2 rounded-md bg-accent-strong font-semibold whitespace-nowrap text-on-accent shadow-card hover:bg-accent-strong-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-not-allowed disabled:opacity-60 ${sizeClasses[size]}`}
        />
    );
}
