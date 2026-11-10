import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
};

/**
 * Butonul „Șterge” pentru un repository: cere confirmare, apoi trimite DELETE /repositories/{id}.
 * Serverul îl șterge și răspunde cu redirect la pagina principală („/”), care se deschide de sus.
 */
export default function DeleteRepositoryButton({ repository }: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    function handleClick() {
        if (!window.confirm('Ștergi acest repository?')) {
            return;
        }

        router.delete(`/repositories/${repository.id}`, {
            onStart: () => setIsDeleting(true),
            onFinish: () => setIsDeleting(false),
        });
    }

    return (
        <button
            type="button"
            onClick={handleClick}
            disabled={isDeleting}
            className="inline-flex shrink-0 cursor-pointer items-center justify-center rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-danger-ink shadow-xs ring-1 ring-danger-line ring-inset hover:bg-danger-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-danger disabled:cursor-not-allowed disabled:opacity-60"
        >
            {isDeleting ? 'Se șterge…' : 'Șterge'}
            {/* Text citit doar de cititoarele de ecran, ca să știe ce repository se șterge. */}
            <span className="sr-only"> {repository.full_name}</span>
        </button>
    );
}
