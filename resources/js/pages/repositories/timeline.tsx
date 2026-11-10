import { Head } from '@inertiajs/react';
import ComingSoon from '@/components/repository/coming-soon';
import RepositoryLayout from '@/layouts/repository-layout';
import type { RepositoryPageProps } from '@/types';

/** Tab-ul „Istoric”. Deocamdată spune doar ce va apărea aici. */
export default function RepositoriesTimeline({ repository }: RepositoryPageProps) {
    return (
        <RepositoryLayout>
            <Head title={`Istoric · ${repository.full_name}`} />

            <ComingSoon title="Istoric">
                Commit-urile pe luni, rulările GitChronicle peste ele și „călătoria în timp”: documentația așa cum era la un commit procesat mai vechi.
            </ComingSoon>
        </RepositoryLayout>
    );
}
