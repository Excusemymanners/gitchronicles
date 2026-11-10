import { Head } from '@inertiajs/react';
import ComingSoon from '@/components/repository/coming-soon';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/** Tab-ul „Diagrame”. Deocamdată spune doar ce va apărea aici. */
export default function RepositoriesDiagrams({ repository }: RepositoryPageProps) {
    return (
        <RepositoryLayout>
            <Head title={`Diagrame · ${repository.full_name}`} />

            <ComingSoon title="Diagrame">
                Diagramele de arhitectură C4 (Context, Container, Component), desenate din analiza codului, și fluxurile principale ale aplicației, cu legături la cod.
            </ComingSoon>
        </RepositoryLayout>
    );
}
