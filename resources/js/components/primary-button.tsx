import type { ButtonHTMLAttributes } from 'react';
import Button from '@/components/button';

type Props = Omit<ButtonHTMLAttributes<HTMLButtonElement>, 'className'> & {
    /** "lg" is the button of a large form (the home page, log in, sign up); "md" the usual one. */
    size?: 'md' | 'lg';
    /** The form is being sent: a spinner before the label, and the button cannot be pressed again. */
    loading?: boolean;
};

/**
 * The main button of a form: the system's primary button (components/button.tsx), submitting by default.
 * Kept as its own component because many forms already use it by this name.
 */
export default function PrimaryButton({ type = 'submit', size = 'md', ...props }: Props) {
    return <Button variant="primary" type={type} size={size} {...props} />;
}
