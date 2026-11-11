import { router } from '@inertiajs/react';
import { useState } from 'react';
import PrimaryButton from '@/components/primary-button';
import type { RepositoryItem } from '@/types';

type Props = {
    repository: RepositoryItem;
    /** „compact”: perechea lui „Verifică actualizări” din bara de sus; „primary”: acțiunea unei stări goale. */
    variant?: 'compact' | 'primary';
};

const focusRing = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent';

/**
 * „Regenerează tot”: cere confirmare, apoi trimite POST /repositories/{id}/regenerate. Serverul pornește o
 * procesare completă (toate etapele, toate capitolele), iar pagina urmărește rularea singură, ca după
 * „Verifică actualizări”. Perechea lor: „Verifică actualizări” reface doar ce au schimbat commit-urile noi.
 */
export default function RegenerateButton({ repository, variant = 'compact' }: Props) {
    const [isStarting, setIsStarting] = useState(false);

    function handleClick() {
        const question =
            `Regenerezi tot pentru ${repository.full_name}?\n\n` +
            'Repository-ul trece din nou prin toate etapele: clonare, analiza codului, istoric, documentație, diagrame și indexul asistentului. ' +
            'Paginile al căror cod nu s-a schimbat rămân la fel.';

        if (!window.confirm(question)) {
            return;
        }

        router.post(
            `/repositories/${repository.id}/regenerate`,
            {},
            {
                preserveScroll: true,
                onStart: () => setIsStarting(true),
                onFinish: () => setIsStarting(false),
            },
        );
    }

    const label = isStarting ? 'Se pornește…' : 'Regenerează tot';

    if (variant === 'primary') {
        return (
            <PrimaryButton type="button" onClick={handleClick} disabled={isStarting}>
                {label}
            </PrimaryButton>
        );
    }

    return (
        <button
            type="button"
            onClick={handleClick}
            disabled={isStarting}
            title="Reface toate capitolele, de la clonare până la indexul asistentului"
            className={`pressable inline-flex cursor-pointer items-center rounded-md bg-surface px-2.5 py-1 text-[0.8125rem] font-medium text-ink shadow-xs ring-1 ring-line-strong ring-inset hover:bg-surface-2 disabled:cursor-not-allowed disabled:opacity-60 ${focusRing}`}
        >
            {label}
        </button>
    );
}
