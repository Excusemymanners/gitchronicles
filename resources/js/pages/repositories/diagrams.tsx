import { Head } from '@inertiajs/react';
import DiagramsContent from '@/components/diagrams/diagrams-content';
import type { DiagramSet } from '@/components/diagrams/types';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

type Props = RepositoryPageProps & {
    /** Diagramele desenate de BuildDiagrams (Faza 7). */
    diagrams: DiagramSet;
};

/** Tab-ul „Diagrame”: fluxurile „Cum funcționează”, straturile și vederile C4 ale repository-ului. */
export default function RepositoriesDiagrams({ repository, diagrams }: Props) {
    return (
        <RepositoryLayout>
            <Head title={`Diagrame · ${repository.full_name}`} />

            <DiagramsContent diagrams={diagrams} />
        </RepositoryLayout>
    );
}
